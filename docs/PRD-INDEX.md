# PRD INDEX
# Sistem Absensi Mahasiswa Berbasis Mobile
# Geolocation + Face Recognition (MobileFaceNet)
# Politeknik Negeri Pontianak - Jurusan Teknik Elektro

---

**Versi**: 1.1
**Tanggal**: 27 Mei 2026 (indeks); catatan canonical diperbarui 11 Agustus 2026
**Author**: Yusril Eka Mahendra, M.TI
**Status**: Maintained requirements index; detail runtime lihat `docs/README.md`

---

## DAFTAR DOKUMEN PRD

> PRD menjelaskan kebutuhan dan desain produk. Kontrak runtime, API, deployment,
> security, dan status remediation mengikuti [README dokumentasi](README.md).

| No | File | Isi | Halaman |
|----|------|-----|---------|
| 1 | [PRD-01-overview.md](./PRD-01-overview.md) | Overview, Tujuan, Scope, Hierarki Role (8 role), Tech Stack, Arsitektur Sistem | ~330 baris |
| 2 | [PRD-02-functional-requirements.md](./PRD-02-functional-requirements.md) | Functional Requirements: Auth, Enrollment, Absensi (Check-in/out), Izin/Sakit, Manajemen Akademik, Konfigurasi | ~350 baris |
| 3 | [PRD-02B-functional-requirements.md](./PRD-02B-functional-requirements.md) | Functional Requirements (lanjutan): Early Warning SP, Monitoring & Rekapitulasi, Dosen Approval, Notifikasi, Mode Pengujian | ~300 baris |
| 4 | [PRD-03-database-design.md](./PRD-03-database-design.md) | Database Design: ERD, 21 tabel MySQL lengkap dengan relasi, index, dan constraint | ~400 baris |
| 5 | [PRD-04-api-design.md](./PRD-04-api-design.md) | API Design: 80+ endpoint REST API, request/response format, error handling | ~350 baris |
| 6 | [PRD-05-flow-diagram.md](./PRD-05-flow-diagram.md) | Flow Diagram: 10 alur proses (Check-in, Check-out, Auto-close, Alpha, SP, Enrollment, Izin, Override, Offline Sync) | ~300 baris |
| 7 | [PRD-06-ui-ux-design.md](./PRD-06-ui-ux-design.md) | UI/UX Design: Design System, Mobile App (Mahasiswa + Dosen), Web Dashboard Layout, Sidebar per Role, Komponen UI | ~350 baris |
| 8 | [PRD-07-analisis-evaluasi.md](./PRD-07-analisis-evaluasi.md) | Menu Analisis & Evaluasi: 7 sub-menu (Geofence, Face Verify, Latensi, Kehadiran/SP, Uji Simultan, Perbandingan, Dokumentasi Teknis) | ~350 baris |
| 9 | [PRD-08-non-functional.md](./PRD-08-non-functional.md) | Non-Functional Requirements: Performance, Security, Anti-Spoofing, Deployment, Testing, Timeline, Risiko | ~300 baris |

---

## DAFTAR TASK DOCUMENTS

| No | File | Isi | Status |
|----|------|-----|--------|
| 1 | [task-backend.md](./task-backend.md) | Original backend implementation plan | Historis |
| 2 | [task-frontend.md](./task-frontend.md) | Original standalone SPA plan | Historis/superseded |
| 3 | [task-mobile.md](./task-mobile.md) | Original mobile implementation plan | Historis |
| 4 | [task-master.md](./task-master.md) | Original master timeline | Historis |

---

## RINGKASAN SISTEM

### Platform
- **Mobile App** (Flutter): Alur mahasiswa; hanya Android yang termasuk release matrix, iOS tidak didukung
- **Web Dashboard** (Laravel Inertia 3 + Vue 3 + Vite): Untuk role dashboard
- **Backend/API** (Laravel 13): REST API, web session, queue, dan scheduler dalam satu aplikasi
- **Database** (MySQL 8): Penyimpanan data

### Hierarki Role (8 Role Domain)
1. Super Admin (Owner/Peneliti)
2. Ketua Jurusan
3. Admin Jurusan
4. Kaprodi
5. Admin Prodi
6. Dosen
7. Mahasiswa
8. Orang Tua

### Fitur Utama
1. Target produk: absensi berbasis Face Verification (MobileFaceNet) + Geofencing
2. Target produk: Active Liveness Detection; belum boleh diklaim tahan presentation attack
3. Target produk: Deteksi Mock Location; belum boleh diklaim mencegah fake GPS absolut
4. Akumulasi alpha berbasis menit (presisi tinggi)
5. Early Warning System SP (SP1/SP2/SP3/DO) otomatis
6. Generate dokumen SP dengan tanda tangan digital (Kaprodi + Kajur)
7. Multi mata kuliah per hari (check-in/out per sesi)
8. Offline attendance mode (queue + sync)
9. Dashboard monitoring real-time per role
10. Menu Analisis & Evaluasi Sistem (khusus penelitian)
11. Mode Pengujian FAR/FRR
12. Export Excel/PDF
13. Web Push (VAPID); lifecycle FCM mobile tersedia sebagai explicit release opt-in (`ENABLE_FCM_PUSH=true` + Firebase config)

### Aturan SP (Akumulasi Jam Alpha per Semester)
- AMAN: 0 - 15 jam
- SP1: 16 - 31 jam
- SP2: 32 - 37 jam
- SP3: 38 - 45 jam
- DO: >= 46 jam

Ambang ini adalah default `prodi_settings` dan sama dengan `AppConstants` mobile. Detail runtime lain lihat [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md).

### Keputusan Canonical Terkini (11 Agustus 2026)
- Face match: `face_distance <= face_threshold` di mobile, backend, dan analisis (L-08/R-04).
- Baseline GPS accuracy minimum: 20 m (`prodi_settings.gps_accuracy_minimum`, seeder, `AppConstants`).
- Analisis geofence: success rate dari `checkin_success`/`checkin_failed`, bukan `geofence_valid` (R-01).
- Lifecycle master historis: FK `ON DELETE RESTRICT` + arsip soft delete (M-19); invariant domain ditegakkan database (M-20).
- Session cookie production fail-closed; throttle login/TOTP; revocation sesi lain saat ganti password (M-21).
- Attendance/enrollment production fail-closed sampai trusted verifier tersedia (C-04/H-04).
- Platform mobile release Android-only; iOS tidak didukung (H-17).
- FCM lifecycle tersedia sebagai explicit opt-in; default release off (L-02).
- Workflow Backend/Frontend CI sudah didefinisikan; remote enforcement/evidence masih mengikuti L-09.

### Flow Absensi
```
Permit server → Geofence → Liveness challenge → Face Match → Submit evidence → Consume permit
```

Flow tersebut hanya compatibility/non-production sampai trusted verifier tersedia. Production saat ini berhenti di gate `TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`.

### Timeline
- Total estimasi: ~22 minggu (5.5 bulan)
- Sesuai jadwal penelitian: Maret - November 2026
