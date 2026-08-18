# Arsitektur Saat Ini

**Status:** maintained
**Pembaruan:** 11 Agustus 2026
**Authority:** executable truth; backlog dan evidence mengikuti [temuan.md](temuan.md)

## Komponen

| Komponen | Implementasi | Authentication |
|---|---|---|
| Web dashboard | Laravel 13 + Inertia 3 + Vue 3 + Vite 8 di `backend/` | Laravel session |
| Mobile mahasiswa | Flutter, BLoC, Dio, secure storage, Hive terenkripsi | Sanctum bearer token |
| API/domain | Laravel controllers, policy/authorization service, domain service | Sanctum + role + active-user middleware |
| Data | MySQL untuk target produksi; migrations adalah schema authority | DB constraints, FK restrict lifecycle, CHECK invariant, dan transaction |
| Async | Laravel queue dan scheduler | Worker/cron internal |

Web dashboard bukan SPA terpisah. Vue berada di `backend/resources/js` dan dirender melalui Inertia. Flutter adalah client terpisah untuk alur mahasiswa.

## Role Canonical

Sistem memiliki **8 role**. Sumber kebenaran adalah `backend/database/seeders/RoleSeeder.php`.

| ID | Role | Akses |
|---:|---|---|
| 1 | `super_admin` | Global |
| 2 | `ketua_jurusan` | Dashboard web |
| 3 | `admin_jurusan` | Dashboard web, fail-closed ke `prodi_id` aktor |
| 4 | `kaprodi` | Dashboard web, scope prodi |
| 5 | `admin_prodi` | Dashboard web, scope prodi |
| 6 | `dosen` | Dashboard web, terbatas mata kuliah yang diampu |
| 7 | `mahasiswa` | Mobile |
| 8 | `orang_tua` | API anak |

Enam role pertama dapat masuk dashboard web. `mahasiswa` dan `orang_tua` bukan pengguna dashboard. Klaim "7 role" pada dokumen historis tidak berlaku.

Tabel di atas hanya ringkasan cakupan. Matriks lengkap — guard per route, aturan
scope query, hierarki assignability, guard non-role, dan checklist audit negative
test — ada di [ROLE-PERMISSION-MATRIX.md](ROLE-PERMISSION-MATRIX.md) dan itulah
sumber kebenaran authorization (MS-01).

## Trust Boundary Attendance

1. Client meminta attendance permit untuk user, jadwal, action, `client_uuid`, dan optional `attendance_id`.
2. Server memeriksa akun/enrollment, enrollment mata kuliah, prodi, jadwal, mata kuliah, geofence, semester, tahun ajaran, hari, tanggal akademik, dan time window.
3. Permit menyimpan hash token dan terikat ke occurrence/action/resource. Permit memiliki `not_before`, capture expiry, dan sync expiry.
4. Pada compatibility mode non-production, client melakukan geolocation, liveness, dan face matching, lalu mengirim evidence dengan permit.
5. Server memvalidasi binding dan mengonsumsi permit atomik sebelum membuat/mengubah attendance.

Permit mencegah request tanpa preauthorization, wrong binding, dan replay. Permit belum membuat koordinat, face distance, atau liveness result menjadi bukti yang diverifikasi independen oleh server. Karena itu production memasang `RequireTrustedBiometricEvidence` dan menolak permit, attendance online/offline, enrollment/re-enrollment, reference embedding, serta approval biometrik dengan `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`. Compatibility switch hanya berlaku di environment non-production.

Data legacy/test bersifat **client-attested**, bukan bukti forensik. Production tetap menolak data tersebut. Trusted verifier server-side (C-04/H-04) **di luar scope penelitian**: rancangannya ditinjau di [ADR-001](ADR-001-trusted-biometric-verifier.md) dan **ditolak**, sehingga face matching/liveness tetap on-device dan production sengaja fail-closed. Ini batasan penelitian yang diterima sebagai residual risk, bukan pekerjaan tertunda. C-04/H-04 dan batas klaim lengkap ada di [THREAT-MODEL-ATTENDANCE.md](THREAT-MODEL-ATTENDANCE.md), [ADR-001](ADR-001-trusted-biometric-verifier.md), dan [temuan.md](temuan.md).

## Attendance Window

- Batas awal: `jam_mulai - toleransi_masuk_menit`.
- Batas akhir capture: `jam_selesai + toleransi_pulang_menit`.
- Kedua boundary bersifat inklusif.
- Timezone canonical aplikasi dan scheduler adalah `Asia/Pontianak`.
- Online menggunakan waktu server sebagai capture time.
- Offline capture harus berada di capture window dan disinkronkan sebelum `sync_expires_at`.

## Offline Queue

- Box Hive terenkripsi dan terpisah per immutable user ID.
- Key queue disimpan di OS secure storage.
- Item membawa owner, status, retry count, dan `syncStartedAt`.
- Lease syncing default lima menit; lease stale dipulihkan menjadi pending saat aktivasi/restart.
- Logout menunggu lifecycle queue, lalu menghapus box dan key milik user.
- Offline attendance memerlukan permit yang telah diterbitkan dan masih valid; bukan attendance tanpa preauthorization server.

## Identity Lifecycle

- Semua protected request memeriksa `users.status=aktif` melalui `user.active`.
- Deactivation mencabut Sanctum token dan database session.
- Akun hasil import/provisioning tanpa password eksplisit menggunakan random placeholder, `activation_pending=true`, dan status nonaktif.
- Reset token satu kali mengatur password dan hanya mengaktifkan akun yang memang `activation_pending`.
- Demo user seeder tidak dijalankan pada environment production.
- Ganti password (web) mencabut seluruh Sanctum token dan session database milik user selain sesi web saat ini, lalu me-regenerate sesi aktif (M-21).

## Master Data Lifecycle (Restrict/Archive)

Rekam akademik historis tidak boleh hilang karena penghapusan master secara transitif (M-19).

- FK dari rekam historis ke master memakai `ON DELETE RESTRICT`: `attendances(user_id, jadwal_id, mata_kuliah_id)`, `sp_records(user_id, semester_id)`, `leave_requests(user_id, mata_kuliah_id)`, `alpha_accumulations(user_id, semester_id)`, `attendance_logs(user_id)`, dan `face_embeddings(user_id)`.
- Database menolak hard delete master (user/jadwal/mata_kuliah/semester) selama masih ada riwayat terkait.
- Kolom aktor (`approved_by`, `overridden_by`, `generated_by`, `signed_*`) tetap `SET NULL`; `attendance_logs.attendance_id` tetap `SET NULL` agar log bertahan meski attendance dihapus.
- Jalur normal menonaktifkan master adalah arsip: `MataKuliah`, `Jadwal`, dan `User` memakai soft delete (dapat di-restore tanpa kehilangan sejarah). Semester/Tahun Ajaran/Prodi dinonaktifkan lewat flag `status` dan guard controller memblokir hapus saat masih ada anak/riwayat.

## Invariant Domain di Database (M-20)

Selain validasi aplikasi, database menegakkan invariant berikut sebagai lapisan terakhir:

- CHECK: koordinat/radius geofence, `jam_selesai > jam_mulai` pada jadwal, urutan tanggal semester/tahun ajaran, `sks`/`total_pertemuan` positif, toleransi/geofence/persentase, dan urutan threshold SP1<SP2<SP3<DO pada `prodi_settings`.
- UNIQUE: duplikat mata kuliah dengan `kelas IS NULL` ditutup via generated column `kelas_key = COALESCE(kelas,'')` plus unique `(kode_mk, semester_id, kelas_key)`.
- Composite index mengikuti query utama monitoring/report: `attendances(user_id,tanggal)`, `attendances(jadwal_id,tanggal)`, `attendances(mata_kuliah_id,status)`, `jadwals(hari,status)`, `face_embeddings(user_id,status)`.

## Face Matching Canonical

- Comparator match wajah canonical adalah `distance <= threshold`, konsisten di mobile (`FaceRecognitionService`, `EnrollmentIdentityContinuity`), backend (`face_distance > threshold` ⇒ tolak), dan analisis FAR/FRR (L-08/R-04).
- Baseline GPS accuracy minimum canonical adalah 20 m: default `prodi_settings.gps_accuracy_minimum`, seeder, `AppConstants.gpsAccuracyMinimum`, dan pre-check UI mobile. Nilai per-prodi di server tetap menjadi sumber kebenaran runtime.
- Ambang SP (16/32/38/46 jam) sama antara `prodi_settings` dan `AppConstants` mobile.
- Analisis geofence menghitung success rate dari `checkin_success` vs `checkin_failed`, bukan `geofence_valid` (R-01). Distribusi jarak tetap dari log geofence.

## Push Notification Lifecycle (FCM)

Lifecycle FCM mobile diimplementasikan di `frontend/lib/core/notifications/push_messaging_service.dart` dan diwire ke `AuthBloc` (L-02).

- Inisialisasi Firebase + handler `onMessage`/`onBackgroundMessage`/`onMessageOpenedApp`, `requestPermission`, `getToken`, dan `onTokenRefresh` (selalu didorong ulang ke backend).
- Register token setelah login/`CheckAuthStatus` sukses; revoke (`deleteToken` + `POST /fcm-token` kosong) saat logout dan `SessionInvalidated`. Revoke pada perangkat bersama mencegah push milik akun sebelumnya (C-06).
- **Explicit opt-in:** release default memakai `ENABLE_FCM_PUSH=false`, sehingga tidak mencoba atau mengklaim push. Bila diaktifkan, workflow mewajibkan dan menginjeksi `google-services.json` dari protected secret; konfigurasi hilang membuat build gagal.
- Backend siap: `POST /fcm-token` (set/clear), `FcmService` (HTTP v1), dan `NotificationService::pushFcm`. Pengiriman push nyata butuh Firebase project + `FIREBASE_CREDENTIALS_PATH`.
- Lifecycle dan kontrak release L-02 selesai; smoke test push nyata tetap menjadi release checklist environment-specific, bukan alasan aplikasi mengklaim FCM aktif tanpa konfigurasi.

## Biometrik dan Private Files

- Face embedding dienkripsi AES-256-GCM dengan biometric key terpisah dari `APP_KEY`.
- Key ID/keyring mendukung rotasi; plaintext legacy dipurge melalui migration.
- Foto enrollment/re-enrollment dan dokumen izin berada pada private disk.
- Akses file memerlukan authentication, signed URL berumur pendek, object-level authorization, dan response `private, no-store`.

## Platform Support

| Platform | Status |
|---|---|
| Android | Target release aktif; signing release melalui CI/secret manager |
| iOS | Tidak didukung dan dikeluarkan dari release matrix; folder platform hanya scaffold pengembangan |
| Web dashboard | Target aktif melalui backend Inertia |

Jangan menerbitkan artifact iOS. Membuka dukungan iOS memerlukan release decision, macOS CI/signing, capability, dan physical-iPhone evidence baru.

## Release Readiness

- Workflow backend/frontend CI sudah didefinisikan, tetapi remote green run, branch protection, required checks, dan protected environment belum terbukti; lihat L-09.
- Checkout navigation H-13 selesai.
- Camera converter harness tersedia, tetapi H-16 menunggu Firebase Test Lab physical Android low/mid/high evidence.
- FCM L-02 selesai sebagai explicit opt-in; default release tetap off.
- Sistem **memenuhi tujuan penelitian** (face recognition on-device + geofencing terbukti), tetapi **belum production-ready**: C-04/H-04 sengaja di luar scope penelitian ([ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak) dan evidence release L-09/H-16 masih terbuka. Menaikkan ke produksi mengharuskan C-04/H-04 dibuka kembali.
