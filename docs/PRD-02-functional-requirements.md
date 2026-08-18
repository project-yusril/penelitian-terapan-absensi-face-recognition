# PRD-02: FUNCTIONAL REQUIREMENTS

> **Interpretasi current:** product intent tetap berlaku, tetapi provisioning
> akun, eligibility attendance, permit/window, dan offline semantics mengikuti
> [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md), [CURRENT-API.md](CURRENT-API.md),
> dan [SECURITY.md](SECURITY.md). NIM/NIDN atau password universal tidak pernah
> menjadi credential awal. Mobile release hanya untuk mahasiswa Android; fungsi
> Dosen berjalan di dashboard web. Attendance/enrollment production
> fail-closed; trusted verifier (C-04/H-04) di luar scope penelitian ([ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak).

## 1. MODUL AUTENTIKASI & USER MANAGEMENT

### 1.1 Registrasi & Login

#### FR-AUTH-001: Login Multi-Platform
- **Deskripsi**: User dapat login menggunakan email/NIM + password
- **Platform**: Mobile (Mahasiswa, Dosen) dan Web (semua role)
- **Detail**:
  - Input: email/NIM + password
  - Output: JWT token (Sanctum)
  - Sistem mendeteksi role user dan mengarahkan ke dashboard sesuai role
  - Session timeout: 24 jam (mobile), 8 jam (web)
  - Remember me option (web): 30 hari

#### FR-AUTH-002: Registrasi Mahasiswa
- **Deskripsi**: Admin Prodi mendaftarkan mahasiswa ke sistem
- **Detail**:
  - Admin input: NIM, nama lengkap, email, prodi, kelas/angkatan
  - Sistem generate password default (NIM + 4 digit random)
  - Mahasiswa wajib ganti password saat login pertama
  - Setelah ganti password, diarahkan ke enrollment wajah

#### FR-AUTH-003: Registrasi Dosen
- **Deskripsi**: Admin Prodi/Admin Jurusan mendaftarkan dosen ke sistem
- **Detail**:
  - Admin input: NIDN, nama lengkap, email, prodi
  - Sistem generate password default
  - Dosen wajib ganti password saat login pertama

#### FR-AUTH-004: Logout
- **Deskripsi**: User dapat logout dari sistem
- **Detail**:
  - Revoke token aktif
  - Clear local storage/session
  - Redirect ke halaman login

#### FR-AUTH-005: Forgot Password
- **Deskripsi**: User dapat reset password via email
- **Detail**:
  - Input email terdaftar
  - Sistem kirim link reset (valid 60 menit)
  - User set password baru

#### FR-AUTH-006: Change Password
- **Deskripsi**: User dapat mengubah password
- **Detail**:
  - Input: password lama, password baru, konfirmasi password baru
  - Validasi: min 8 karakter, kombinasi huruf + angka

---

## 2. MODUL ENROLLMENT WAJAH

### 2.1 Self-Enrollment

#### FR-ENROLL-001: Pendaftaran Wajah Pertama Kali
- **Deskripsi**: Mahasiswa mendaftarkan wajah saat pertama kali login
- **Platform**: Mobile
- **Prasyarat**: Sudah login dan ganti password default
- **Konfigurasi Kamera**:
  - Resolusi preview: `ResolutionPreset.high` (1280x720) — kualitas visual bagus
  - Format stream: YUV420 (untuk ML processing)
  - Foto enrollment: `takePicture()` resolusi penuh HP (bukan dari stream)
- **Flow**:
  1. Sistem tampilkan instruksi enrollment
  2. Aktifkan kamera depan (720p preview — kualitas bagus, tidak buram)
  3. Real-time face detection (ML Kit) dari stream 720p
  4. Validasi real-time: 1 wajah, menghadap depan, tidak pakai masker, mata terdeteksi
  5. Semua validasi hijau → liveness challenge (1 challenge random):
     - Senyum / Toleh kiri / Toleh kanan / Kedip mata / Angguk
  6. Jika liveness lolos:
     - **Foto enrollment**: `controller.takePicture()` → resolusi penuh HP → compress JPG 85% (~100-200KB)
     - **Embedding**: ambil frame dari stream → crop face (bounding box ML Kit) → resize 112x112 → normalisasi → MobileFaceNet → embedding 192-dim
  7. Kirim embedding + foto enrollment ke backend (multipart request)
  8. Backend simpan foto di storage (path disimpan di `users.foto_enrollment`)
  9. Status enrollment: PENDING (menunggu approval admin)
- **Catatan Teknis**:
  - Foto enrollment menggunakan `takePicture()` (resolusi penuh) agar kualitas bagus untuk biodata
  - Embedding menggunakan frame dari stream (720p) karena akan di-resize ke 112x112 anyway — resolusi asal tidak berpengaruh terhadap akurasi embedding
  - Preview 720p sudah cukup bagus secara visual dan tidak membebani performa ML Kit
- **Validasi**:
  - Hanya 1 wajah yang boleh terdeteksi dalam frame
  - Pencahayaan cukup (brightness check)
  - Wajah menghadap depan (head euler angle dalam range)

#### FR-ENROLL-002: Approval Enrollment oleh Admin
- **Deskripsi**: Admin Prodi menyetujui/menolak enrollment wajah
- **Platform**: Web Dashboard
- **Detail**:
  - Admin melihat daftar enrollment pending
  - Info yang ditampilkan: nama, NIM, foto enrollment, tanggal enrollment, status liveness
  - Admin bisa approve atau reject (dengan alasan)
  - Jika reject, mahasiswa harus enrollment ulang
  - Jika approve, embedding aktif dan mahasiswa bisa mulai absensi
  - Foto enrollment tetap tersimpan sebagai identitas visual mahasiswa

#### FR-ENROLL-003: Request Re-enrollment
- **Deskripsi**: Mahasiswa request enrollment ulang (perubahan penampilan)
- **Platform**: Mobile
- **Detail**:
  - Mahasiswa submit request dengan alasan (dropdown: potong rambut, pakai jilbab, perubahan lain)
  - Request masuk ke admin prodi
  - Admin approve -> mahasiswa bisa enrollment ulang
  - Embedding lama di-nonaktifkan setelah enrollment baru di-approve
  - Riwayat enrollment tersimpan (audit trail)

---

## 3. MODUL ABSENSI (CHECK-IN / CHECK-OUT)

### 3.1 Proses Check-in

#### FR-ABS-001: Validasi Lokasi (Geofencing)
- **Deskripsi**: Sistem memvalidasi lokasi mahasiswa sebelum absensi
- **Platform**: Mobile
- **Flow**:
  1. Ambil koordinat GPS (latitude, longitude)
  2. Cek mock location (safe_device):
     - Jika terdeteksi fake GPS -> TOLAK, tampilkan "Terdeteksi manipulasi lokasi"
     - Log anomaly ke backend
  3. Hitung jarak ke titik geofence mata kuliah yang sedang berlangsung
  4. Metode: Geolocator.distanceBetween() atau Haversine manual
  5. Bandingkan dengan radius geofence yang ditentukan
  6. Jika jarak <= radius -> VALID, lanjut ke face verification
  7. Jika jarak > radius -> TOLAK, tampilkan "Anda di luar area perkuliahan"
- **Data yang dicatat**:
  - Koordinat mahasiswa (lat, lon)
  - Jarak ke titik geofence (meter)
  - Status validasi (valid/invalid)
  - Mock location detected (true/false)
  - Akurasi GPS (meter)

#### FR-ABS-002: Liveness Detection (Challenge-Response)
- **Deskripsi**: Sistem memastikan wajah yang dipindai adalah wajah asli real-time
- **Platform**: Mobile
- **Prasyarat**: Validasi lokasi berhasil
- **Flow**:
  1. Aktifkan kamera depan
  2. Sistem pilih 1 challenge random dari pool:
     - "Senyum" -> deteksi smilingProbability > 0.7
     - "Tolehkan kepala ke kiri" -> headEulerAngleY < -20 derajat
     - "Tolehkan kepala ke kanan" -> headEulerAngleY > 20 derajat
     - "Kedipkan mata" -> eyeOpenProbability < 0.3 lalu > 0.7
     - "Anggukkan kepala" -> headEulerAngleX perubahan > 15 derajat
  3. Tampilkan instruksi challenge ke user
  4. Timeout: 10 detik per challenge
  5. Jika berhasil -> lanjut ke face verification
  6. Jika gagal -> boleh coba lagi (tidak ada limit, tapi dicatat di log)
- **Anti-spoofing tambahan**:
  - Deteksi apakah wajah terlalu flat (indikasi foto/layar)
  - Cek variasi pencahayaan pada wajah (wajah asli punya depth)

#### FR-ABS-003: Face Verification
- **Deskripsi**: Sistem memverifikasi identitas mahasiswa via embedding wajah
- **Platform**: Mobile
- **Prasyarat**: Liveness detection berhasil
- **Konfigurasi Kamera**: Sama dengan saat liveness — preview 720p (ResolutionPreset.high)
- **Flow**:
  1. Ambil frame wajah terbaik dari stream 720p (proses liveness)
  2. Crop wajah berdasarkan bounding box ML Kit
  3. Resize ke 112x112 piksel (resolusi asal tidak berpengaruh — target hanya 112x112)
  4. Normalisasi: x_norm = (x - 127.5) / 127.5
  5. Jalankan MobileFaceNet TFLite -> embedding 192-dim (di memori HP)
  6. Ambil embedding referensi dari local cache (atau fetch dari server)
  7. Hitung Euclidean Distance: d = sqrt(sum((e_i - t_i)^2))
  8. Bandingkan dengan threshold (configurable, default ~1.0)
  9. Jika d < threshold -> MATCH, absensi valid
  10. Jika d >= threshold -> NOT MATCH, tampilkan "Verifikasi wajah gagal"
  11. **Frame wajah dan embedding sementara DIBUANG dari memori** (tidak disimpan)
- **Data yang dikirim ke backend** (hanya angka, BUKAN foto/embedding):
  - Euclidean distance value (float)
  - Threshold yang digunakan (float)
  - Hasil (match/not match)
  - Waktu inferensi (ms)
  - Device info (model, OS version)
- **PENTING — Yang TIDAK disimpan saat absensi**:
  - ❌ Foto wajah (tidak disimpan, tidak dikirim)
  - ❌ Embedding baru (tidak disimpan, hanya diproses di memori lalu dibuang)
  - ❌ Frame kamera (dibuang setelah proses selesai)

#### FR-ABS-004: Check-in Mahasiswa
- **Deskripsi**: Mahasiswa melakukan check-in di awal perkuliahan
- **Platform**: Mobile
- **Prasyarat**: Geofence valid + Liveness valid + Face match
- **Flow**:
  1. Setelah semua validasi berhasil
  2. Sistem catat waktu check-in
  3. Tentukan status berdasarkan waktu:
     - Jika check-in <= jam_mulai + toleransi_masuk -> Status: HADIR
     - Jika check-in > jam_mulai + toleransi_masuk DAN <= batas_terlambat -> Status: HADIR (TERLAMBAT)
     - Jika check-in > batas_terlambat -> Status: PENDING (perlu approval dosen)
  4. Hitung akumulasi alpha (jika terlambat):
     - alpha_menit = waktu_checkin - jam_mulai_matakuliah
     - (toleransi tidak dihitung sebagai alpha)
  5. Kirim data ke backend
  6. Tampilkan konfirmasi: "Check-in berhasil - [STATUS]"
- **Aturan Toleransi (configurable per prodi)**:
  - toleransi_masuk: default 15 menit (check-in tanpa penalty)
  - batas_terlambat: default 50% durasi kuliah (setelah ini = pending)

#### FR-ABS-005: Check-out Mahasiswa
- **Deskripsi**: Mahasiswa melakukan check-out di akhir perkuliahan
- **Platform**: Mobile
- **Prasyarat**: Sudah check-in sebelumnya
- **Flow**:
  1. Validasi lokasi (geofence) - sama seperti check-in
  2. Liveness detection (1 challenge random)
  3. Face verification
  4. Sistem catat waktu check-out
  5. Tentukan status:
     - Jika check-out >= jam_selesai - toleransi_pulang -> Normal
     - Jika check-out < jam_selesai - toleransi_pulang -> PULANG AWAL
  6. Hitung akumulasi alpha (jika pulang awal):
     - alpha_menit += jam_selesai_matakuliah - waktu_checkout
  7. Hitung durasi kehadiran efektif:
     - durasi_efektif = waktu_checkout - waktu_checkin
  8. Kirim data ke backend
  9. Tampilkan konfirmasi: "Check-out berhasil"
- **Aturan Toleransi (configurable per prodi)**:
  - toleransi_pulang: default 15 menit (check-out setelah jam selesai masih bisa)

#### FR-ABS-006: Auto-Close Check-out
- **Deskripsi**: Sistem otomatis menutup absensi jika mahasiswa lupa check-out
- **Platform**: Backend (scheduler)
- **Detail**:
  - Jika mahasiswa sudah check-in tapi tidak check-out sampai jam_selesai + toleransi_pulang
  - Sistem auto-close dengan waktu check-out = jam_selesai (resmi)
  - Tidak ada penalty alpha tambahan
  - Flag: auto_closed = true (untuk monitoring admin)

#### FR-ABS-007: Multi Mata Kuliah per Hari
- **Deskripsi**: Mahasiswa bisa absen di beberapa mata kuliah dalam 1 hari
- **Detail**:
  - Contoh: Matematika 07:00-10:00, Statistika 13:00-15:00
  - Setiap mata kuliah punya sesi check-in dan check-out terpisah
  - Akumulasi alpha dihitung per mata kuliah lalu dijumlahkan
  - Mahasiswa hanya bisa check-in pada mata kuliah yang sedang berlangsung (berdasarkan jadwal)

#### FR-ABS-008: Absensi Offline (Queue)
- **Deskripsi**: Mahasiswa bisa absen saat koneksi internet buruk
- **Platform**: Mobile
- **Detail**:
  - Validasi geofence: dilakukan offline (koordinat geofence di-cache lokal)
  - Liveness + Face verification: dilakukan offline (on-device)
  - Data absensi disimpan di local queue (Hive/SQLite)
  - Saat koneksi kembali, data di-sync ke backend
  - Data yang di-queue: timestamp, koordinat, distance, face distance, device info
  - Backend validasi: timestamp harus masih dalam range waktu mata kuliah
  - Flag: synced_offline = true (untuk monitoring)

---

## 4. MODUL IZIN & SAKIT

#### FR-IZIN-001: Upload Surat Izin/Sakit
- **Deskripsi**: Mahasiswa mengupload surat izin atau surat sakit
- **Platform**: Mobile
- **Detail**:
  - Mahasiswa pilih jenis: IZIN atau SAKIT
  - Pilih mata kuliah yang tidak dihadiri, **atau** aktifkan opsi "Berlaku untuk semua MK"
  - Pilih tanggal (bisa range tanggal untuk sakit berkepanjangan)
  - Upload foto/scan surat (JPG/PNG/PDF, max 5MB)
  - Tambah keterangan (opsional)
  - Status: PENDING approval

- **Opsi semua mata kuliah (sakit sehari)**:
  - Satu submit menghasilkan **satu izin per mata kuliah KRS aktif** pada semester dan tahun ajaran aktif yang periodenya mencakup rentang pengajuan serta mempunyai jadwal aktif; model data tetap per-MK sehingga alpha/SP/rekap tidak berubah
  - MK tanpa jadwal pada rentang, dan MK yang sudah punya izin PENDING/APPROVED dengan rentang tanggal yang beririsan, dilewati dan dilaporkan ke mahasiswa
  - Satu surat dipakai bersama seluruh baris yang terbentuk
  - Approval tetap per baris: menyetujui satu izin tidak mengubah mata kuliah lain
  - Kontrak payload dan bentuk response ada di [CURRENT-API.md](CURRENT-API.md)

#### FR-IZIN-002: Approval Izin/Sakit
- **Deskripsi**: Dosen atau Admin Prodi menyetujui/menolak izin
- **Platform**: Web Dashboard + Mobile (Dosen)
- **Detail**:
  - Dosen/Admin melihat daftar izin pending
  - Bisa lihat surat yang diupload
  - Approve: status kehadiran berubah menjadi IZIN/SAKIT (alpha = 0)
  - Reject: status kehadiran tetap ALPHA (dengan alasan penolakan)
  - Notifikasi ke mahasiswa setelah di-approve/reject

---

## 5. MODUL MANAJEMEN AKADEMIK (ADMIN PRODI)

### 5.1 Tahun Ajaran

#### FR-AKAD-001: CRUD Tahun Ajaran
- **Deskripsi**: Admin mengelola data tahun ajaran
- **Detail**:
  - Field: kode (auto), nama (misal "2025/2026"), tanggal_mulai, tanggal_selesai, status (aktif/nonaktif)
  - Hanya 1 tahun ajaran yang boleh aktif
  - Saat aktivasi tahun ajaran baru, yang lama otomatis nonaktif

### 5.2 Semester

#### FR-AKAD-002: CRUD Semester
- **Deskripsi**: Admin mengelola data semester
- **Detail**:
  - Field: kode, nama (Ganjil/Genap), tahun_ajaran_id, tanggal_mulai, tanggal_selesai, status (aktif/nonaktif)
  - Terkait dengan tahun ajaran
  - Saat ganti semester, akumulasi alpha mahasiswa reset ke 0

### 5.3 Mata Kuliah

#### FR-AKAD-003: CRUD Mata Kuliah
- **Deskripsi**: Admin mengelola data mata kuliah
- **Detail**:
  - Field: kode_mk, nama_mk, sks, semester_id, prodi_id, dosen_id (pengampu), kelas
  - Satu mata kuliah bisa diampu oleh 1 dosen
  - Satu mata kuliah bisa punya beberapa kelas (A, B, C)

### 5.4 Jadwal Perkuliahan

#### FR-AKAD-004: CRUD Jadwal Perkuliahan
- **Deskripsi**: Admin mengelola jadwal perkuliahan
- **Detail**:
  - Field: mata_kuliah_id, hari (Senin-Sabtu), jam_mulai, jam_selesai, ruangan_id, geofence_id
  - Validasi: tidak boleh ada jadwal bentrok (dosen/ruangan/kelas yang sama di waktu yang sama)
  - Durasi otomatis dihitung: jam_selesai - jam_mulai (dalam menit)
  - Total pertemuan per semester (default 16 pertemuan)

### 5.5 Lokasi Geofence

#### FR-AKAD-005: CRUD Lokasi Geofence
- **Deskripsi**: Admin mengelola titik lokasi geofence
- **Detail**:
  - Field: nama_lokasi (misal "Lab Komputer 1"), latitude, longitude, radius (meter), gedung, lantai
  - Preview di peta (Google Maps/Leaflet)
  - Radius configurable per lokasi (default 50 meter)
  - Bisa assign ke jadwal perkuliahan

### 5.6 Manajemen Mahasiswa

#### FR-AKAD-006: CRUD Data Mahasiswa
- **Deskripsi**: Admin mengelola data mahasiswa
- **Detail**:
  - Field: NIM, nama, email, no_hp, prodi_id, kelas, angkatan, status (aktif/nonaktif/DO)
  - Import bulk via Excel (template disediakan)
  - Export data mahasiswa ke Excel
  - Assign mahasiswa ke mata kuliah/kelas

### 5.7 Manajemen Dosen

#### FR-AKAD-007: CRUD Data Dosen
- **Deskripsi**: Admin mengelola data dosen
- **Detail**:
  - Field: NIDN, nama, email, no_hp, prodi_id, jabatan_fungsional
  - Assign dosen ke mata kuliah

---

## 6. MODUL KONFIGURASI SISTEM (PER PRODI)

#### FR-CONFIG-001: Setting Toleransi Waktu
- **Deskripsi**: Admin Prodi mengatur toleransi waktu absensi
- **Detail**:
  - toleransi_masuk: menit (default 15)
  - batas_terlambat_persen: persentase durasi kuliah (default 50%)
  - toleransi_pulang: menit (default 15)
  - Configurable per prodi (masing-masing prodi bisa beda)

#### FR-CONFIG-002: Setting Threshold SP
- **Deskripsi**: Admin Prodi mengatur batas akumulasi alpha untuk SP
- **Detail**:
  - SP1: jam_mulai (default 16), jam_akhir (default 31)
  - SP2: jam_mulai (default 32), jam_akhir (default 37)
  - SP3: jam_mulai (default 38), jam_akhir (default 45)
  - DO: jam_mulai (default 46), jam_akhir (unlimited)
  - Configurable per prodi

#### FR-CONFIG-003: Setting Face Verification
- **Deskripsi**: Super Admin mengatur parameter face verification
- **Detail**:
  - threshold_distance: float (default 1.0)
  - liveness_challenge_count: integer (default 1)
  - liveness_timeout: detik (default 10)
  - max_failed_attempts_before_flag: integer (default 5)

#### FR-CONFIG-004: Setting Geofence
- **Deskripsi**: Admin Prodi mengatur parameter geofence
- **Detail**:
  - default_radius: meter (default 50)
  - gps_accuracy_minimum: meter (default 20)
  - allow_offline_attendance: boolean (default true)
  - offline_sync_timeout: menit (default 30)
