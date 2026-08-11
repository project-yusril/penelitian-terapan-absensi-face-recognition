# PRD-02B: FUNCTIONAL REQUIREMENTS (Lanjutan)

> **Interpretasi current:** fitur dan penerima notifikasi harus diverifikasi
> terhadap implementation/tests dan [temuan.md](temuan.md). Lifecycle FCM mobile
> selesai sebagai explicit opt-in: default release off, sedangkan opt-in
> mewajibkan Firebase secret/config dan smoke test runtime. Web Push VAPID adalah
> capability terpisah. Mobile Dosen pada requirement lama tidak termasuk release
> matrix; fungsi Dosen current ada di dashboard web.

## 7. MODUL EARLY WARNING & SURAT PERINGATAN (SP)

### 7.1 Perhitungan Akumulasi Alpha

#### FR-SP-001: Kalkulasi Otomatis Akumulasi Alpha
- **Deskripsi**: Sistem menghitung akumulasi alpha secara real-time
- **Platform**: Backend (auto-calculate setiap ada record absensi baru)
- **Detail Perhitungan**:
  ```
  Untuk setiap record absensi mahasiswa di semester aktif:
  
  CASE 1: Hadir tepat waktu (dalam toleransi)
    alpha_menit = 0
  
  CASE 2: Hadir terlambat (di luar toleransi masuk)
    alpha_menit = waktu_checkin - jam_mulai_matakuliah
    (toleransi TIDAK dikurangi, dihitung dari jam mulai resmi)
  
  CASE 3: Pulang awal (sebelum jam selesai - toleransi pulang)
    alpha_menit += jam_selesai_matakuliah - waktu_checkout
  
  CASE 4: Tidak hadir (alpha penuh)
    alpha_menit = durasi_matakuliah (jam_selesai - jam_mulai dalam menit)
  
  CASE 5: Izin/Sakit (approved)
    alpha_menit = 0
  
  CASE 6: Pending (belum di-approve dosen)
    alpha_menit = sementara dihitung sebagai alpha penuh
    (berubah setelah dosen approve/reject)
  
  Total Akumulasi Alpha (jam) = SUM(semua alpha_menit) / 60
  ```
- **Trigger recalculate**:
  - Setiap check-in/check-out baru
  - Setiap approval/rejection oleh dosen
  - Setiap approval izin/sakit
  - Scheduler tiap menit (auto-close dan alpha penuh dieksekusi segera setelah tiap jadwal selesai, bukan menunggu akhir hari)

#### FR-SP-002: Deteksi Status SP Otomatis
- **Deskripsi**: Sistem otomatis mendeteksi mahasiswa yang masuk kategori SP
- **Detail**:
  ```
  total_alpha_jam = total akumulasi alpha dalam jam (semester aktif)
  
  IF total_alpha_jam >= 16 AND total_alpha_jam <= 31 THEN status = SP1
  IF total_alpha_jam >= 32 AND total_alpha_jam <= 37 THEN status = SP2
  IF total_alpha_jam >= 38 AND total_alpha_jam <= 45 THEN status = SP3
  IF total_alpha_jam >= 46 THEN status = DO
  ELSE status = AMAN
  ```
- **Notifikasi otomatis (berdasarkan level)**:

  **Mendekati SP1 (80% dari 16 jam = 12.8 jam):**
  | Penerima | Channel | Pesan |
  |----------|---------|-------|
  | Mahasiswa | Push (HP) | "Peringatan: Akumulasi alpha Anda sudah X jam. Batas SP1 adalah 16 jam." |

  **Masuk SP1 (16 jam):**
  | Penerima | Channel | Pesan |
  |----------|---------|-------|
  | Mahasiswa | Push (HP) | "Anda menerima Surat Peringatan 1. Total alpha: X jam." |
  | Admin Prodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) masuk kategori SP1" |
  | Kaprodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) masuk kategori SP1" |
  | Dosen Pengampu MK terkait | In-app (web) | "Mahasiswa [Nama] di kelas Anda masuk kategori SP1" |

  **Mendekati SP2 (80% dari 32 jam = 25.6 jam):**
  | Penerima | Channel | Pesan |
  |----------|---------|-------|
  | Mahasiswa | Push (HP) | "Peringatan: Akumulasi alpha Anda sudah X jam. Batas SP2 adalah 32 jam." |
  | Admin Prodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) mendekati batas SP2" |

  **Masuk SP2 (32 jam):**
  | Penerima | Channel | Pesan |
  |----------|---------|-------|
  | Mahasiswa | Push (HP) | "Anda menerima Surat Peringatan 2. Total alpha: X jam." |
  | Admin Prodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) masuk kategori SP2" |
  | Kaprodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) masuk kategori SP2" |
  | Ketua Jurusan | In-app (web) | "Mahasiswa [Nama] ([NIM]) Prodi [Prodi] masuk kategori SP2" |

  **Mendekati SP3 (80% dari 38 jam = 30.4 jam):**
  | Penerima | Channel | Pesan |
  |----------|---------|-------|
  | Mahasiswa | Push (HP) | "Peringatan: Akumulasi alpha Anda sudah X jam. Batas SP3 adalah 38 jam." |
  | Admin Prodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) mendekati batas SP3" |
  | Kaprodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) mendekati batas SP3" |

  **Masuk SP3 (38 jam):**
  | Penerima | Channel | Pesan |
  |----------|---------|-------|
  | Mahasiswa | Push (HP) | "Anda menerima Surat Peringatan 3. Total alpha: X jam." |
  | Admin Prodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) masuk kategori SP3" |
  | Kaprodi | In-app (web) | "Mahasiswa [Nama] ([NIM]) masuk kategori SP3" |
  | Ketua Jurusan | In-app (web) | "Mahasiswa [Nama] ([NIM]) Prodi [Prodi] masuk kategori SP3" |
  | Admin Jurusan | In-app (web) | "Mahasiswa [Nama] ([NIM]) Prodi [Prodi] masuk kategori SP3" |

  **Mendekati DO (80% dari 46 jam = 36.8 jam):**
  | Penerima | Channel | Pesan |
  |----------|---------|-------|
  | Mahasiswa | Push (HP) | "PERINGATAN KERAS: Akumulasi alpha Anda sudah X jam. Batas DO adalah 46 jam." |
  | Admin Prodi | In-app (web) | "URGENT: Mahasiswa [Nama] ([NIM]) mendekati batas DO" |
  | Kaprodi | In-app (web) | "URGENT: Mahasiswa [Nama] ([NIM]) mendekati batas DO" |
  | Ketua Jurusan | In-app (web) | "URGENT: Mahasiswa [Nama] ([NIM]) Prodi [Prodi] mendekati batas DO" |

  **Masuk DO (46 jam):**
  | Penerima | Channel | Pesan |
  |----------|---------|-------|
  | Mahasiswa | Push (HP) | "Anda telah mencapai batas Drop Out. Total alpha: X jam. Hubungi admin prodi." |
  | Admin Prodi | In-app (web) | "URGENT: Mahasiswa [Nama] ([NIM]) masuk kategori DO" |
  | Kaprodi | In-app (web) | "URGENT: Mahasiswa [Nama] ([NIM]) masuk kategori DO" |
  | Ketua Jurusan | In-app (web) | "URGENT: Mahasiswa [Nama] ([NIM]) Prodi [Prodi] masuk kategori DO" |
  | Admin Jurusan | In-app (web) | "URGENT: Mahasiswa [Nama] ([NIM]) Prodi [Prodi] masuk kategori DO" |

#### FR-SP-003: Generate Dokumen SP
- **Deskripsi**: Sistem generate dokumen Surat Peringatan (PDF)
- **Platform**: Web Dashboard (Admin Prodi)
- **Detail Dokumen SP**:
  - Header: Logo Polnep, Jurusan Teknik Elektro, Prodi
  - Nomor surat (auto-generate)
  - Tanggal surat
  - Perihal: Surat Peringatan [1/2/3] / Pemberhentian Studi
  - Isi:
    - Nama mahasiswa, NIM, Prodi, Kelas
    - Total akumulasi alpha (jam dan menit)
    - Rincian ketidakhadiran per mata kuliah
    - Pernyataan peringatan sesuai level SP
  - Tanda tangan:
    - Kaprodi (tanda tangan digital)
    - Diketahui oleh: Ketua Jurusan (tanda tangan digital)
  - Tembusan: Mahasiswa, Orang tua/wali, Arsip
- **Flow Generate SP**:
  1. Admin Prodi klik "Generate SP" pada mahasiswa yang terdeteksi
  2. Sistem generate draft PDF
  3. Admin review draft
  4. Kirim ke Kaprodi untuk tanda tangan digital
  5. Kaprodi approve + tanda tangan
  6. Kirim ke Ketua Jurusan untuk "diketahui" + tanda tangan
  7. Ketua Jurusan approve + tanda tangan
  8. Dokumen final tersimpan di sistem
  9. Notifikasi ke mahasiswa (bisa download PDF)

#### FR-SP-004: Tanda Tangan Digital
- **Deskripsi**: Kaprodi dan Ketua Jurusan menandatangani dokumen SP secara digital
- **Platform**: Web Dashboard
- **Detail**:
  - Upload tanda tangan (gambar PNG transparan) saat setup akun
  - Saat approve dokumen SP, tanda tangan otomatis ditempelkan ke PDF
  - Timestamp approval tercatat
  - Audit trail: siapa yang approve, kapan

#### FR-SP-005: Riwayat SP Mahasiswa
- **Deskripsi**: Sistem menyimpan riwayat SP per mahasiswa
- **Detail**:
  - Daftar semua SP yang pernah diterima
  - Status: Draft, Menunggu TTD Kaprodi, Menunggu TTD Kajur, Final
  - Tanggal terbit
  - Dokumen PDF tersimpan
  - Bisa diakses oleh: mahasiswa (read), admin, kaprodi, kajur

---

## 8. MODUL MONITORING & REKAPITULASI

### 8.1 Dashboard Overview

#### FR-REKAP-001: Dashboard Admin Prodi
- **Deskripsi**: Overview kehadiran prodi
- **Detail Charts & Data**:
  - Card: Total mahasiswa aktif, Total hadir hari ini, Total alpha hari ini, Total pending
  - Chart: Trend kehadiran mingguan (line chart)
  - Chart: Distribusi status kehadiran (pie chart)
  - Chart: Top 10 mahasiswa alpha terbanyak (bar chart)
  - Table: Mahasiswa yang mendekati/sudah SP (sortable, filterable)
  - Table: Pending approval terbaru

#### FR-REKAP-002: Dashboard Kaprodi
- **Deskripsi**: Overview monitoring prodi
- **Detail**:
  - Card: Total mahasiswa, SP1 count, SP2 count, SP3 count, DO count
  - Chart: Trend SP per bulan (stacked bar)
  - Chart: Persentase kehadiran per mata kuliah (horizontal bar)
  - Table: Daftar mahasiswa per status SP
  - Alert: Mahasiswa yang baru masuk kategori SP (highlight)

#### FR-REKAP-003: Dashboard Ketua Jurusan
- **Deskripsi**: Overview seluruh jurusan (3 prodi)
- **Detail**:
  - Card: Total mahasiswa per prodi, Total SP per prodi
  - Chart: Perbandingan kehadiran antar prodi (grouped bar)
  - Chart: Trend SP seluruh jurusan (line chart)
  - Table: Summary per prodi (kehadiran rata-rata, jumlah SP)
  - Pending: Dokumen SP/DO yang menunggu tanda tangan

#### FR-REKAP-004: Dashboard Dosen
- **Deskripsi**: Overview kelas yang diampu
- **Platform**: Web + Mobile
- **Detail**:
  - Card: Jumlah kelas hari ini, Total pending approval, Kehadiran rata-rata
  - Table: Jadwal hari ini + status (sudah mulai/belum/selesai)
  - Table: Pending approval (terlambat + izin/sakit)
  - Per kelas: daftar mahasiswa + status kehadiran hari ini

### 8.2 Rekapitulasi Detail

#### FR-REKAP-005: Rekap Kehadiran per Mahasiswa
- **Deskripsi**: Detail kehadiran satu mahasiswa
- **Detail**:
  - Filter: semester, mata kuliah
  - Summary: total hadir, total alpha (jam:menit), total izin, total sakit, persentase kehadiran
  - Table: riwayat per pertemuan (tanggal, matkul, check-in, check-out, durasi efektif, alpha menit, status)
  - Progress bar: akumulasi alpha vs threshold SP
  - Export: Excel, PDF

#### FR-REKAP-006: Rekap Kehadiran per Mata Kuliah
- **Deskripsi**: Detail kehadiran satu mata kuliah
- **Detail**:
  - Filter: kelas, rentang tanggal
  - Summary: rata-rata kehadiran, total pertemuan terlaksana
  - Table: daftar mahasiswa + kehadiran per pertemuan (matrix view)
  - Highlight: mahasiswa dengan kehadiran rendah
  - Export: Excel, PDF

#### FR-REKAP-007: Rekap Kehadiran per Kelas
- **Deskripsi**: Detail kehadiran satu kelas di semua mata kuliah
- **Detail**:
  - Filter: semester
  - Table: mahasiswa x mata kuliah (persentase kehadiran)
  - Ranking kehadiran
  - Export: Excel, PDF

#### FR-REKAP-008: Export Laporan
- **Deskripsi**: Export data ke Excel/PDF
- **Format Excel**:
  - Sheet 1: Summary (nama, NIM, total hadir, total alpha, status SP)
  - Sheet 2: Detail per pertemuan
  - Sheet 3: Rincian alpha per mata kuliah
- **Format PDF**:
  - Laporan formal dengan header institusi
  - Tabel rekap
  - Tanda tangan penanggung jawab

---

## 9. MODUL DOSEN (APPROVAL & OVERRIDE)

#### FR-DOSEN-001: Approve/Reject Pending Kehadiran
- **Deskripsi**: Dosen memutuskan status mahasiswa yang terlambat melebihi batas
- **Platform**: Web + Mobile
- **Detail**:
  - Daftar mahasiswa dengan status PENDING di kelas yang diampu
  - Info: nama, NIM, waktu check-in, keterlambatan (menit)
  - Action: Approve (ubah ke HADIR TERLAMBAT) atau Reject (ubah ke ALPHA penuh)
  - Jika approve: alpha dihitung dari jam mulai sampai waktu check-in
  - Jika reject: alpha = durasi penuh mata kuliah
  - Notifikasi ke mahasiswa setelah keputusan

#### FR-DOSEN-002: Override Manual Kehadiran
- **Deskripsi**: Dosen mengubah status kehadiran mahasiswa secara manual
- **Platform**: Web + Mobile
- **Detail**:
  - Use case: HP mahasiswa rusak, mahasiswa hadir tapi tidak bisa absen digital
  - Dosen pilih mahasiswa + mata kuliah + tanggal
  - Ubah status ke: HADIR / ALPHA / IZIN
  - Wajib isi alasan override
  - Audit trail: tercatat siapa yang override, kapan, alasan
  - Notifikasi ke admin prodi (untuk monitoring)

#### FR-DOSEN-003: Lihat Kehadiran Real-time
- **Deskripsi**: Dosen melihat siapa saja yang sudah check-in di kelas yang sedang berlangsung
- **Platform**: Mobile (real-time)
- **Detail**:
  - List mahasiswa: sudah check-in (hijau), belum check-in (abu-abu)
  - Auto-refresh setiap 30 detik
  - Counter: X dari Y mahasiswa sudah hadir

---

## 10. MODUL NOTIFIKASI

#### FR-NOTIF-001: Push Notification ke Mahasiswa
- **Deskripsi**: Notifikasi otomatis ke HP mahasiswa
- **Trigger**:
  - Mendekati batas SP (80% threshold): "Peringatan: Alpha Anda sudah X jam"
  - Masuk kategori SP baru: "Anda menerima SP[1/2/3]"
  - Izin/sakit di-approve/reject: "Izin Anda telah [disetujui/ditolak]"
  - Pending di-approve/reject oleh dosen
  - Reminder absen (15 menit sebelum kelas dimulai): "Kelas [nama] dimulai 15 menit lagi"
  - Enrollment di-approve/reject

#### FR-NOTIF-002: In-App Notification (Web Dashboard)
- **Deskripsi**: Notifikasi di web dashboard
- **Trigger**:
  - Admin: enrollment baru pending, mahasiswa masuk SP baru
  - Dosen: pending approval baru, izin/sakit baru
  - Kaprodi: SP baru yang perlu ditandatangani
  - Kajur: dokumen SP/DO yang perlu ditandatangani
- **Detail**:
  - Bell icon dengan badge counter
  - Dropdown list notifikasi (mark as read)
  - Halaman semua notifikasi (filterable)

---

## 11. MODUL MODE PENGUJIAN (SUPER ADMIN)

#### FR-TEST-001: Aktivasi Mode Pengujian
- **Deskripsi**: Super Admin mengaktifkan mode pengujian untuk evaluasi FAR/FRR
- **Platform**: Web Dashboard (Super Admin only)
- **Detail**:
  - Toggle: mode_pengujian = ON/OFF
  - Saat ON, setiap absensi dicatat dengan label tambahan:
    - Jenis percobaan: GENUINE atau IMPOSTOR
  - Mahasiswa yang berpartisipasi dalam pengujian ditandai
  - Data pengujian terpisah dari data operasional

#### FR-TEST-002: Skenario Pengujian Genuine
- **Deskripsi**: Mahasiswa absen menggunakan akun sendiri (seharusnya berhasil)
- **Detail**:
  - Mahasiswa login akun sendiri
  - Lakukan absensi normal
  - Sistem catat: genuine_attempt, result (accept/reject), distance, threshold

#### FR-TEST-003: Skenario Pengujian Impostor
- **Deskripsi**: Mahasiswa coba absen menggunakan akun orang lain (seharusnya gagal)
- **Detail**:
  - Mahasiswa A login akun mahasiswa B (dengan izin, untuk pengujian)
  - Coba verifikasi wajah
  - Sistem catat: impostor_attempt, result (accept/reject), distance, threshold
  - Data ini digunakan untuk menghitung FAR

#### FR-TEST-004: Log Pengujian Simultan
- **Deskripsi**: Mencatat performa saat banyak mahasiswa absen bersamaan
- **Detail**:
  - Catat: jumlah concurrent users, response time per request
  - Catat: success rate, failure rate, timeout rate
  - Catat: rata-rata waktu total proses absensi (dari buka app sampai selesai)
  - Skenario: 20, 30, 40 mahasiswa bersamaan
