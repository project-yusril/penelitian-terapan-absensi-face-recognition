# PRD INDEX
# Sistem Absensi Mahasiswa Berbasis Mobile
# Geolocation + Face Recognition (MobileFaceNet)
# Politeknik Negeri Pontianak - Jurusan Teknik Elektro

---
**Versi**: 1.2

**Tanggal**: 27 Mei 2026 (indeks); catatan canonical diperbarui 12 September 2026
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

### Keputusan Canonical Terkini (11 Agustus 2026; diperbarui 20 Agustus 2026 — RENCANA 2)
- Face match: `face_distance <= face_threshold` di mobile, backend, dan analisis (L-08/R-04).
- Baseline GPS accuracy minimum: 20 m (`prodi_settings.gps_accuracy_minimum`, seeder, `AppConstants`).
- Analisis geofence: success rate dari `checkin_success`/`checkin_failed`, bukan `geofence_valid` (R-01).
- Lifecycle master historis: FK `ON DELETE RESTRICT` + arsip soft delete (M-19); invariant domain ditegakkan database (M-20).
- Session cookie production fail-closed; throttle login/TOTP; revocation sesi lain saat ganti password (M-21).
- Rate limit: group API terautentikasi memakai `throttle:api` 60/menit **per user**, `POST /auth/change-password` memakai `throttle:auth-sensitive` 5/menit per user. Keying per user, bukan per IP, agar NAT kampus tidak saling mengunci (M-23).
- Authorization: matriks role/permission/prodi canonical ada di ROLE-PERMISSION-MATRIX.md; enforcement tiga lapis (guard role, object policy, query scope) dan filter request hanya boleh mempersempit scope (H-21/MS-01).
- Dataset analisis penelitian: `prodi_id` mempersempit dataset — bukan hanya memilih threshold — dengan atribusi prodi subjek (`users.prodi_id`), dan endpoint analisis memakai scope aktor sehingga role tingkat prodi tidak dapat membaca prodi lain (R-04/M-24).
- Attendance/enrollment production aktif dengan bukti client-attested sejak 21 September 2026 (keputusan pemilik proyek; revisi ADR-001). Trusted verifier (C-04/H-04) tetap di luar scope penelitian; residual risk diterima. Tanpa flag, gate kembali fail-closed.
- Platform mobile release Android-only; iOS tidak didukung (H-17).
- FCM lifecycle tersedia sebagai explicit opt-in; default release off (L-02).
- Workflow Backend/Frontend CI sudah didefinisikan; remote enforcement/evidence masih mengikuti L-09.
- **RENCANA 2 (kelas master, 20 Agustus 2026):** mata kuliah = master kurikulum (tanpa `kelas`/`dosen_id`); dosen & kelas di-plot per jadwal (`jadwals.dosen_id`/`kelas_id`); KRS diturunkan dari `mahasiswa_kelas` → kelas → jadwal; pivot `mahasiswa_mata_kuliah` dihapus; `users.kelas`/`semester` = snapshot. Unik: `mata_kuliahs(kode_mk, semester_id, prodi_id)`, `kelas(prodi_id, semester_id, tingkat, nama)`, `mahasiswa_kelas(user_id, semester_id)`. Detail & verifikasi: rencana2.md, CURRENT-ARCHITECTURE.md → "Struktur Data Akademik", CURRENT-API.md → "Perubahan Kontrak RENCANA 2".
- **Pembersihan data (12 September 2026):** semester aktif `2026/2027-1` memuat kelas 1A–1E, 3A–3E, 5A–5E; kelas 1A–1E di semester genap 2025/2026 hasil seeding keliru dihapus beserta pivotnya (100% duplikat angkatan 2026, tanpa jadwal/attendance). Arsip genap 2025/2026 hanya 4A–4E (62 attendance riwayat). State data lengkap: CURRENT-ARCHITECTURE.md → "State Data Terkini".
- **Foto attempt berisiko (23–24 September 2026, FR-ABS-009):** foto bukti hanya disimpan untuk attempt berisiko (face gagal/borderline, mock location, liveness gagal, offline sync) — kriteria diputuskan server-side (`AttemptFotoService::riskReasons()`), bukan klaim client. Disk privat + signed URL ber-otorisasi + audit akses + purge 30 hari (`attendance:purge-attempt-fotos`). Geofence kini prodi-scoped: admin prodi hanya mengelola geofence prodinya (`assertCanManageProdiResource()`); geofence global (tanpa prodi) hanya super_admin. Detail: PRD-02 FR-ABS-009, PRD-03 §2.14, CURRENT-API.md → "Online Check-in/Checkout".
- **Backend live (12 September 2026):** backend penelitian berjalan di `https://absensi.yusrilekamahendra.com` (Hostinger); `GET /api/health` → 200. Mobile memakai `--dart-define=API_BASE_URL=https://absensi.yusrilekamahendra.com/api`. **Scheduler aktif sejak 21 September 2026** (cron `schedule:run` per menit; hambatan `proc_open` Hostinger diperbaiki — detail DEPLOYMENT.md). Mail delivery masih harus diverifikasi di host (bagian L-09). Detail: DEPLOYMENT.md → "Backend Production (Live)".

### Flow Absensi
```
Permit server → Geofence → Liveness challenge → Face Match → Submit evidence → Consume permit
```

Flow tersebut aktif di production sejak 21 September 2026 (revisi ADR-001, keputusan pemilik proyek) dengan bukti client-attested — server tidak mengulang liveness/face matching. Mematikan `BIOMETRIC_ALLOW_CLIENT_CLAIMS` mengembalikan production ke gate `TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED` karena trusted verifier tetap di luar scope penelitian ([ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak).

### Timeline
- Total estimasi: ~22 minggu (5.5 bulan)
- Sesuai jadwal penelitian: Maret - November 2026
