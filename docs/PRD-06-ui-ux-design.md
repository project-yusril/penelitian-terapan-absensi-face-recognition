# PRD-06: UI/UX DESIGN

> **Status:** design intent. Tidak semua layar/platform telah diimplementasikan.
> UI web current menggunakan Inertia/Vue di backend; Flutter current berfokus
> pada mahasiswa. Gap aksesibilitas mengikuti L-07 di [temuan.md](temuan.md).

## 1. DESIGN SYSTEM

### 1.1 Color Palette (Light Theme - Soft Tones)

```
Primary Colors:
- Primary:        #4F7CAC (Soft Blue)
- Primary Light:  #7BA3CC
- Primary Dark:   #2D5A8A

Secondary Colors:
- Secondary:      #6BBFAB (Soft Teal)
- Secondary Light:#9DD4C5
- Secondary Dark: #4A9E8A

Status Colors:
- Success/Hadir:     #6BBF7A (Soft Green)
- Warning/Pending:   #E8B84A (Soft Amber)
- Danger/Alpha:      #D97B7B (Soft Red)
- Info/Izin:         #8B7FD4 (Soft Purple)
- Sakit:             #D4A07F (Soft Orange)

Neutral Colors:
- Background:     #F8FAFB
- Surface:        #FFFFFF
- Border:         #E2E8F0
- Text Primary:   #1E293B
- Text Secondary: #64748B
- Text Muted:     #94A3B8
- Sidebar BG:     #F1F5F9
```

### 1.2 Typography
```
Font Family: Inter (Google Fonts)
- Heading 1: 24px, Bold (600)
- Heading 2: 20px, Semibold (600)
- Heading 3: 16px, Semibold (600)
- Body:      14px, Regular (400)
- Caption:   12px, Regular (400)
- Button:    14px, Medium (500)
```

### 1.3 Spacing & Radius
```
Spacing: 4px base (4, 8, 12, 16, 20, 24, 32, 40, 48)
Border Radius:
- Small: 6px (buttons, inputs)
- Medium: 8px (cards)
- Large: 12px (modals, panels)
- Full: 9999px (badges, avatars)

Shadows:
- sm: 0 1px 2px rgba(0,0,0,0.05)
- md: 0 4px 6px rgba(0,0,0,0.07)
- lg: 0 10px 15px rgba(0,0,0,0.1)
```

---

## 2. MOBILE APP (Flutter) - MAHASISWA

### 2.1 Bottom Navigation Bar (4 Tab)

```
┌─────────────────────────────────────────────────────┐
│                                                     │
│              [KONTEN HALAMAN]                        │
│                                                     │
├─────────────────────────────────────────────────────┤
│  🏠 Beranda  │  📍 Absensi  │  📋 Riwayat  │  👤 Profil  │
└─────────────────────────────────────────────────────┘
```

### 2.2 Tab: Beranda (Home)

```
┌─────────────────────────────────────────────────────┐
│ ┌─────────────────────────────────────────────────┐ │
│ │  Selamat Pagi, Muhammad Haris 👋                │ │
│ │  NIM: 3202316055 | TI - Kelas A                │ │
│ └─────────────────────────────────────────────────┘ │
│                                                     │
│ ┌─── Status Kehadiran Semester Ini ───────────────┐ │
│ │  ┌────┐  ┌────┐  ┌────┐  ┌────┐               │ │
│ │  │ 85%│  │ 12 │  │  3 │  │ 2  │               │ │
│ │  │Hadir│  │Hadir│  │Alpha│  │Izin│              │ │
│ │  └────┘  └────┘  └────┘  └────┘               │ │
│ └─────────────────────────────────────────────────┘ │
│                                                     │
│ ┌─── Akumulasi Alpha ────────────────────────────┐  │
│ │  ████████░░░░░░░░░░░░░░░░░░░░  8.5 jam / 16   │  │
│ │  Status: AMAN                                   │  │
│ │  Sisa sebelum SP1: 7.5 jam                     │  │
│ └─────────────────────────────────────────────────┘ │
│                                                     │
│ ┌─── Jadwal Hari Ini ────────────────────────────┐  │
│ │                                                 │  │
│ │  ● 07:00 - 10:00                              │  │
│ │    Matematika Diskrit                          │  │
│ │    Lab Komputer 1 | Yusril Eka M., M.TI       │  │
│ │    Status: ✅ Sudah Check-in (07:05)           │  │
│ │                                                 │  │
│ │  ● 13:00 - 15:00                              │  │
│ │    Statistika & Probabilitas                   │  │
│ │    R. Teori 3 | Karfindo, M.Kom               │  │
│ │    Status: ⏳ Belum dimulai                    │  │
│ │                                                 │  │
│ └─────────────────────────────────────────────────┘ │
│                                                     │
│ ┌─── Notifikasi Terbaru ─────────────────────────┐  │
│ │  🔔 Izin sakit Anda telah disetujui (2 jam lalu)│ │
│ │  🔔 Reminder: Kelas Statistika 30 menit lagi   │ │
│ └─────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

### 2.3 Tab: Absensi

```
┌─────────────────────────────────────────────────────┐
│                                                     │
│  ┌─── Mata Kuliah Aktif ─────────────────────────┐  │
│  │                                                │  │
│  │  Matematika Diskrit                           │  │
│  │  07:00 - 10:00 | Lab Komputer 1              │  │
│  │  Dosen: Yusril Eka Mahendra, M.TI            │  │
│  │                                                │  │
│  └────────────────────────────────────────────────┘ │
│                                                     │
│  ┌─── Status Validasi ───────────────────────────┐  │
│  │                                                │  │
│  │  📍 Lokasi:    ✅ Valid (25m dari titik)       │  │
│  │  🛡️ GPS:       ✅ Asli (bukan fake)           │  │
│  │  😊 Liveness:  ⏳ Menunggu...                 │  │
│  │  🔐 Wajah:     ⏳ Menunggu...                 │  │
│  │                                                │  │
│  └────────────────────────────────────────────────┘ │
│                                                     │
│  ┌────────────────────────────────────────────────┐ │
│  │                                                │ │
│  │         ┌──────────────────┐                   │ │
│  │         │                  │                   │ │
│  │         │   CAMERA VIEW    │                   │ │
│  │         │   (Selfie)       │                   │ │
│  │         │                  │                   │ │
│  │         │  ┌────────────┐  │                   │ │
│  │         │  │ Face Frame │  │                   │ │
│  │         │  └────────────┘  │                   │ │
│  │         │                  │                   │ │
│  │         └──────────────────┘                   │ │
│  │                                                │ │
│  │    💬 "Silakan SENYUM"                        │ │
│  │    ⏱️ 8 detik tersisa                         │ │
│  │                                                │ │
│  └────────────────────────────────────────────────┘ │
│                                                     │
│  ┌────────────────────────────────────────────────┐ │
│  │         [ 🟢 CHECK-IN ]                        │ │
│  │         (atau CHECK-OUT jika sudah check-in)   │ │
│  └────────────────────────────────────────────────┘ │
│                                                     │
└─────────────────────────────────────────────────────┘
```

### 2.4 Tab: Riwayat

```
┌─────────────────────────────────────────────────────┐
│                                                     │
│  Filter: [Semester ▼] [Mata Kuliah ▼]              │
│                                                     │
│  ┌─── Summary ───────────────────────────────────┐  │
│  │  Hadir: 45  |  Alpha: 5  |  Izin: 3  |  Sakit: 2│
│  │  Akumulasi Alpha: 8.5 jam  |  Status: AMAN    │  │
│  └────────────────────────────────────────────────┘ │
│                                                     │
│  ┌─── 27 Mei 2026 ──────────────────────────────┐  │
│  │  Matematika Diskrit                           │  │
│  │  Check-in: 07:05 | Check-out: 09:58          │  │
│  │  Durasi: 2j 53m | Alpha: 0m                  │  │
│  │  Status: ✅ HADIR                             │  │
│  └────────────────────────────────────────────────┘ │
│                                                     │
│  ┌─── 26 Mei 2026 ──────────────────────────────┐  │
│  │  Pemrograman Mobile                           │  │
│  │  Check-in: 07:45 | Check-out: 09:55          │  │
│  │  Durasi: 2j 10m | Alpha: 45m                 │  │
│  │  Status: ⚠️ HADIR (TERLAMBAT)                │  │
│  └────────────────────────────────────────────────┘ │
│                                                     │
│  ┌─── 25 Mei 2026 ──────────────────────────────┐  │
│  │  Jaringan Komputer                            │  │
│  │  Tidak hadir                                  │  │
│  │  Alpha: 180m (3 jam)                          │  │
│  │  Status: ❌ ALPHA                             │  │
│  └────────────────────────────────────────────────┘ │
│                                                     │
└─────────────────────────────────────────────────────┘
```

### 2.5 Tab: Profil

```
┌─────────────────────────────────────────────────────┐
│                                                     │
│  ┌─── Foto Profil ───────────────────────────────┐  │
│  │         ┌──────┐                              │  │
│  │         │ 👤  │  Muhammad Haris              │  │
│  │         └──────┘  NIM: 3202316055            │  │
│  │                    Prodi: Teknik Informatika  │  │
│  │                    Kelas: A | Angkatan: 2023  │  │
│  └────────────────────────────────────────────────┘ │
│                                                     │
│  ┌─── Menu ──────────────────────────────────────┐  │
│  │                                                │  │
│  │  📷 Enrollment Wajah                          │  │
│  │     Status: ✅ Approved                       │  │
│  │     [Request Re-enrollment]                   │  │
│  │                                                │  │
│  │  📄 Izin & Sakit                             │  │
│  │     [Upload Surat Baru]                       │  │
│  │     Riwayat: 5 pengajuan                      │  │
│  │                                                │  │
│  │  📊 Status SP                                 │  │
│  │     Status: AMAN                              │  │
│  │     Akumulasi: 8.5 jam / 16 jam (SP1)        │  │
│  │                                                │  │
│  │  🔑 Ubah Password                            │  │
│  │                                                │  │
│  │  🔔 Pengaturan Notifikasi                    │  │
│  │                                                │  │
│  │  ℹ️ Tentang Aplikasi                         │  │
│  │                                                │  │
│  │  🚪 Logout                                   │  │
│  │                                                │  │
│  └────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
```

---

## 3. MOBILE APP (Flutter) - DOSEN

### 3.1 Bottom Navigation Bar (4 Tab)

```
┌─────────────────────────────────────────────────────┐
│              [KONTEN HALAMAN]                        │
├─────────────────────────────────────────────────────┤
│  🏠 Beranda  │  📚 Kelas  │  ✅ Approval  │  👤 Profil  │
└─────────────────────────────────────────────────────┘
```

### 3.2 Tab: Beranda (Dosen)
```
- Jadwal mengajar hari ini
- Quick stats: pending approval count, kehadiran rata-rata
- Notifikasi terbaru
```

### 3.3 Tab: Kelas (Dosen)
```
- List kelas yang sedang berlangsung
- Per kelas: siapa yang sudah check-in (real-time)
- Counter: X/Y mahasiswa hadir
- Bisa tap untuk lihat detail per mahasiswa
```

### 3.4 Tab: Approval (Dosen)
```
- List pending: terlambat yang perlu approve/reject
- List izin/sakit yang perlu approve/reject
- Swipe action: approve (kanan), reject (kiri)
- Atau tap untuk lihat detail + action buttons
```

### 3.5 Tab: Profil (Dosen)
```
- Data diri
- Override manual kehadiran
- Rekap kelas (link ke web untuk detail)
- Ubah password
- Logout
```

---

## 4. WEB DASHBOARD (Vue.js) - LAYOUT

### 4.1 Layout Utama

```
┌─────────────────────────────────────────────────────────────────────┐
│ HEADER                                                               │
│ ┌─────┐                              🔔 (3)  ┌──────────────────┐  │
│ │LOGO │  Sistem Absensi Mahasiswa             │ Yusril ▼         │  │
│ └─────┘  Politeknik Negeri Pontianak          │ Super Admin      │  │
│                                                └──────────────────┘  │
├────────────┬────────────────────────────────────────────────────────┤
│ SIDEBAR    │  MAIN CONTENT                                          │
│            │                                                         │
│ Dashboard  │  ┌─── Breadcrumb ────────────────────────────────┐     │
│            │  │  Dashboard > Rekap Kehadiran                   │     │
│ Akademik ▼ │  └───────────────────────────────────────────────┘     │
│  Thn Ajaran│                                                         │
│  Semester  │  ┌─── Cards ─────────────────────────────────────┐     │
│  Matkul    │  │ ┌────┐ ┌────┐ ┌────┐ ┌────┐                 │     │
│  Jadwal    │  │ │ 450│ │ 380│ │  45│ │  25│                 │     │
│  Geofence  │  │ │Total│ │Hadir│ │Alpha│ │Pend│                │     │
│            │  │ └────┘ └────┘ └────┘ └────┘                 │     │
│ Users    ▼ │  └───────────────────────────────────────────────┘     │
│  Mahasiswa │                                                         │
│  Dosen     │  ┌─── Charts ────────────────────────────────────┐     │
│            │  │                                                │     │
│ Kehadiran▼ │  │  [Line Chart: Trend Kehadiran Mingguan]       │     │
│  Rekap     │  │                                                │     │
│  Pending   │  └───────────────────────────────────────────────┘     │
│  Enrollment│                                                         │
│            │  ┌─── Table ─────────────────────────────────────┐     │
│ SP       ▼ │  │  Search: [________]  Filter: [Status ▼]       │     │
│  Monitoring│  │                                                │     │
│  Dokumen   │  │  No | Nama | NIM | Alpha | Status | Action    │     │
│            │  │  1  | Haris| 320 | 8.5j  | Aman   | [Detail]  │     │
│ Laporan    │  │  2  | Andi | 321 | 17j   | SP1    | [Detail]  │     │
│            │  │                                                │     │
│ Pengaturan │  │  < 1 2 3 ... 10 >                             │     │
│            │  └───────────────────────────────────────────────┘     │
│ ─────────  │                                                         │
│ Analisis & │                                                         │
│ Evaluasi   │                                                         │
│ (Super     │                                                         │
│  Admin)    │                                                         │
├────────────┴────────────────────────────────────────────────────────┤
│ FOOTER                                                               │
│ © 2026 Sistem Absensi Mahasiswa - Politeknik Negeri Pontianak  v1.0 │
└─────────────────────────────────────────────────────────────────────┘
```

### 4.2 Sidebar Menu per Role

#### Super Admin:
```
📊 Dashboard
👥 Manajemen User
   ├── Semua User
   ├── Role & Permission
📚 Akademik
   ├── Prodi
   ├── Tahun Ajaran
   ├── Semester
   ├── Mata Kuliah
   ├── Jadwal
   ├── Geofence
📋 Kehadiran
   ├── Rekap Kehadiran
   ├── Pending Approval
   ├── Enrollment Wajah
⚠️ Surat Peringatan
   ├── Monitoring SP
   ├── Dokumen SP
📈 Laporan
   ├── Export Data
⚙️ Pengaturan
   ├── Setting Sistem
   ├── Setting per Prodi
   ├── Mode Pengujian
━━━━━━━━━━━━━━━━━━━━
🔬 Analisis & Evaluasi
   ├── Evaluasi Geofence
   ├── Evaluasi Face Verification
   ├── Evaluasi Latensi
   ├── Evaluasi Kehadiran & SP
   ├── Uji Simultan
   ├── Perbandingan Konvensional
   ├── Dokumentasi Teknis
```

#### Admin Prodi:
```
📊 Dashboard
👥 Manajemen User
   ├── Mahasiswa
   ├── Dosen
📚 Akademik
   ├── Tahun Ajaran
   ├── Semester
   ├── Mata Kuliah
   ├── Jadwal
   ├── Geofence
📋 Kehadiran
   ├── Rekap Kehadiran
   ├── Pending Approval
   ├── Enrollment Wajah
   ├── Re-enrollment Request
⚠️ Surat Peringatan
   ├── Monitoring SP
   ├── Generate SP
   ├── Dokumen SP
📈 Laporan
   ├── Rekap per Mahasiswa
   ├── Rekap per Matkul
   ├── Export Data
⚙️ Pengaturan
   ├── Toleransi Waktu
   ├── Threshold SP
   ├── Geofence Default
```

#### Dosen:
```
📊 Dashboard
📚 Kelas Saya
   ├── Jadwal Mengajar
   ├── Kehadiran per Kelas
✅ Approval
   ├── Pending Terlambat
   ├── Izin & Sakit
   ├── Override Manual
📈 Rekap
   ├── Per Mata Kuliah
   ├── Per Mahasiswa
```

#### Kaprodi:
```
📊 Dashboard
👥 Mahasiswa Prodi
⚠️ Monitoring SP
   ├── Status SP
   ├── Approval TTD SP
📈 Laporan
   ├── Rekap Prodi
   ├── Per Mata Kuliah
   ├── Export
```

#### Ketua Jurusan:
```
📊 Dashboard (3 Prodi)
📈 Perbandingan Prodi
⚠️ Monitoring SP
   ├── Overview SP
   ├── Approval TTD SP/DO
📄 Laporan Jurusan
```

#### Admin Jurusan:
```
📊 Dashboard
📈 Rekap Lintas Prodi
📄 Laporan
   ├── Export Jurusan
👥 Data Jurusan
```

---

## 5. KOMPONEN UI UTAMA (Web Dashboard)

### 5.1 Card Statistik
```
┌──────────────────────────┐
│  📊                      │
│  450                     │  ← Angka besar (bold)
│  Total Mahasiswa Aktif   │  ← Label (text-muted)
│  ↑ 5% dari bulan lalu   │  ← Trend (green/red)
└──────────────────────────┘
```

### 5.2 Table dengan Filter
```
┌─────────────────────────────────────────────────────────────────┐
│ Rekap Kehadiran Mahasiswa                          [Export ▼]    │
├─────────────────────────────────────────────────────────────────┤
│ Search: [________________]  Prodi: [All ▼]  Status: [All ▼]    │
├─────────────────────────────────────────────────────────────────┤
│ No │ Nama          │ NIM        │ Alpha (jam) │ Status │ Action │
├────┼───────────────┼────────────┼─────────────┼────────┼────────┤
│ 1  │ Muhammad Haris│ 3202316055 │ 8.5         │ 🟢 Aman│[Detail]│
│ 2  │ Andi Pratama  │ 3202316012 │ 17.0        │ 🟡 SP1 │[Detail]│
│ 3  │ Budi Santoso  │ 3202316033 │ 35.0        │ 🟠 SP2 │[Detail]│
│ 4  │ Citra Dewi   │ 3202316044 │ 40.0        │ 🔴 SP3 │[Detail]│
├─────────────────────────────────────────────────────────────────┤
│ Showing 1-10 of 450          < 1 [2] 3 4 5 ... 45 >            │
└─────────────────────────────────────────────────────────────────┘
```

### 5.3 Chart Examples
```
Line Chart: Trend kehadiran mingguan (x: minggu, y: persentase)
Bar Chart: Kehadiran per mata kuliah
Pie Chart: Distribusi status (hadir/alpha/izin/sakit)
Stacked Bar: SP per bulan per prodi
Progress Bar: Akumulasi alpha vs threshold
```

### 5.4 Modal Approval
```
┌─────────────────────────────────────────────────────┐
│ Approval Kehadiran                            [X]   │
├─────────────────────────────────────────────────────┤
│                                                     │
│ Mahasiswa: Muhammad Haris (3202316055)              │
│ Mata Kuliah: Matematika Diskrit                    │
│ Tanggal: 27 Mei 2026                               │
│ Check-in: 08:35 (terlambat 95 menit)              │
│ Batas terlambat: 08:30 (50% dari 180 menit)       │
│                                                     │
│ Keputusan:                                          │
│ ┌─────────────────┐  ┌─────────────────┐          │
│ │ ✅ APPROVE      │  │ ❌ REJECT       │          │
│ │ (Hadir Terlambat│  │ (Alpha Penuh)   │          │
│ │  Alpha: 95 mnt) │  │  Alpha: 180 mnt)│          │
│ └─────────────────┘  └─────────────────┘          │
│                                                     │
└─────────────────────────────────────────────────────┘
```
