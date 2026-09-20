# Deployment dan Release

**Status:** maintained runbook
**Pembaruan:** 21 September 2026
**Release matrix:** web/backend + Android; iOS tidak didukung

> **Akses SSH ke server production** (IP, port, username, key, pola deploy, backup):
> [`ssh.md`](../ssh.md) — file berisi kredensial dan di-gitignore, hanya ada di lokal workspace.

## Baseline

- PHP 8.3.30, sesuai Composer platform.
- Laravel 13.x.
- Node.js `22.21.1` dan npm `11.6.2` dengan committed lockfile.
- MySQL production dengan database user least-privilege.
- Flutter `3.44.2` / Dart `3.12.2`, sesuai CI dan constraint `pubspec.yaml`.
- JDK 17 untuk Android Gradle/Kotlin build; workflow memakai Temurin 17.
- HTTPS wajib untuk web/API production.

## Development Lokal (Windows/Dev)

Untuk development lokal cukup satu perintah dari `backend/`:

```powershell
php artisan serve:all
```

`serve:all` (lihat `backend/app/Console/Commands/ServeAll.php`) menjalankan dev server **dan** scheduler (`schedule:work`) sekaligus dari satu proses. Default `--host=0.0.0.0 --port=8000`; kedua proses dimonitor — jika salah satu berhenti, semua dihentikan. Alternatif satu perintah penuh:

```powershell
composer dev
```

yang menjalankan `serve:all`, queue listener, log viewer (Pail), dan Vite sekaligus.

> **Penting:** tanpa scheduler yang hidup, `attendance:auto-close` dan `attendance:mark-absent` tidak pernah mengeksekusi sehingga status ALPHA tidak tercatat. `php artisan serve` saja **tidak cukup**; gunakan `serve:all` atau `composer dev`.

## Backend Production (Live)

Backend penelitian **sudah live** pada 12 September 2026 di:

| Item | Nilai |
|---|---|
| Base URL | `https://absensi.yusrilekamahendra.com` |
| API base | `https://absensi.yusrilekamahendra.com/api` |
| Host | Hostinger (hPanel, PHP 8.3.33, HTTP/3 via hCDN) |
| Liveness | `GET /api/health` → `200 {"status":"ok"}` (terverifikasi 12 September 2026) |
| Dashboard web | `GET /` → 302 ke `/login`, cookie session `Secure`+`HttpOnly`+`SameSite=lax` aktif |

Mobile terhubung ke host ini lewat `--dart-define=API_BASE_URL=https://absensi.yusrilekamahendra.com/api` (sudah diuji dari perangkat fisik Android — app boot dan mencapai halaman login). `AppConfig` menerima HTTPS apa pun tanpa allowlist, sehingga tidak ada perubahan kode untuk pindah host.

### Riwayat Deploy via SSH

Deploy langsung dari workspace Windows ke host dilakukan via SSH key (detail koneksi, kredensial, dan pola command: [`ssh.md`](../ssh.md)).

**14 September 2026 — threshold biometrik 0.600 + perbaikan EXIF:** upload 11 file backend (checksum terverifikasi), upload `public/build` hasil `npm run build` lokal (node tidak tersedia di server), clear semua cache Laravel, `UPDATE prodi_settings SET face_threshold = 0.600` (semua 3 prodi), backup penuh DB + storage + `.env` **sebelum** perubahan (lokal: `backup_server_20260914/`). Insiden yang ditemukan & diperbaiki saat deploy: folder hasil `scp -r` ber-permission `700` sehingga apache 404 semua asset dan dashboard blank — fix `chmod 755`/`644` pada `public/build`. Analisis 19 embedding (terdekripsi) menunjukkan 14 enrollment pending 14 September sehat (norm 1.0, jarak antar-orang ~1.0–1.4) — tidak perlu re-enrollment; 1 pasangan mendekati threshold (RIFNO vs Yusril, 0.5752) untuk direview visual via dashboard.

**14 September 2026, 22:58 WIB (15:58 UTC) — fix enrollment foto > 500 KB (M-25, ronde 1):** upload 2 controller via SSH (base64-over-stdin; `scp` bermasalah dari PowerShell — hash SHA256 diverifikasi identik), `php artisan config:clear` + `cache:clear`. Perubahan: validasi foto enrollment/re-enrollment `max:500` → `max:10240` + `dimensions:max_width=6000,max_height=6000` via konstanta `MAX_FOTO_KB`/`MAX_FOTO_DIMENSION_PX`/`fotoRules()` di `EnrollmentController`; foto profil `ProfileController` `max:2048` → `max:10240` + rule dimensi. Kompresi sisi klien (1600 px, q85, ≤ 500 KB) masuk APK build berikutnya (`EnrollmentPhotoCompressor`). Verifikasi deploy: `php -l` bersih, `/api/health` 200, jumlah foto 30 dan hash-chain `932288fc…` **identik** sebelum dan sesudah deploy — tidak ada foto pending yang hilang. Backup sebelum deploy: foto (30 file, 8,9 MB) → `~/backups/face_storage_pre_deploy_20260914/` (server) + `backup_server_face_20260914/` (lokal), dump DB 35 tabel → `~/backups/db_pre_deploy_20260914/db_full.sql.gz` + salinan lokal; controller lama → `~/backups/controllers_pre_deploy_20260914/`.

**14 September 2026, 23:40 WIB (16:40 UTC) — fix hasil code review M-25 (ronde 2):** upload 4 file backend via SSH base64-over-stdin (hash SHA256 terverifikasi identik): `EnrollmentController.php`, `ProfileController.php`, `PhotoUploadPolicy.php` (baru), `routes/api.php`, lalu `config:clear` + `cache:clear` + `route:clear`. Perubahan: rule foto diekstrak ke `App\Services\PhotoUploadPolicy::facePhotoRules()` (dipakai enrollment, re-enrollment, dan foto profil — menghapus duplikasi); pre-check pending re-enrollment sebelum menulis file ke disk; `throttle:biometric-probe` dipasang pada `POST /re-enrollment`. Verifikasi deploy: `php -l` 4 file bersih, `/api/health` 200, foto 34 → 35 (1 foto baru dari user aktif; tidak ada yang hilang), baseline hash baru `34624b84…` dicatat di `backup_server_face_20260914/BASELINE-HASH.txt`.

**21 September 2026, 00:20–01:15 WIB — eksperimen penelitian (TANPA deploy kode):** tidak ada file backend yang berubah. Aktivitas: (1) admin menyetujui 50 enrollment via dashboard sehingga **55 embedding berstatus `approved`** (4 `rejected`, 59 total baris); (2) **evaluasi FAR asli** — script PHP read-only dijalankan di server (`eksperimen/far_eval_server.php` lokal) mendekripsi 55 embedding via `BIOMETRIC_ENCRYPTION_KEY` dari `.env` server, menghitung 1.485 pasangan lintas-user: **FAR @ θ=0.600 = 0.0000%** (min 0.6185, mean 1.1332, maks 1.5083); embedding tidak pernah keluar dari server, hanya angka distance; (3) **benchmark beban 20/30/40 pengguna** — WAF hCDN memblokir k6 eksternal (403 challenge browser), sehingga benchmark dijalankan dari dalam server via curl-loop `--resolve` ke 127.0.0.1, 39 token akun approved (1 token/pengguna), GET-only dashboard/jadwal/history, 45 detik per level: **p95 seluruh level < 2 detik**, failure seluruhnya HTTP 429 dari rate limiter `api` per-user (by design), log Laravel 0 error baru. Verifikasi pasca-aktivitas: `/api/health` 200, dashboard render normal, seluruh skrip kerja dihapus dari server (`kilo_*.sh`, `kilo_far_eval.php`, `impostor_distances.txt`). Bukti & artefak: `eksperimen/` lokal (impostor_distances_20260921.txt, hasil_analisis_far.txt, PROTOKOL_EKSPERIMEN.md, loadtest_server.sh).

Yang **belum diverifikasi** pada host live (masuk L-09 sampai ada bukti):

1. Scheduler/queue worker long-running (`attendance:auto-close`, `attendance:mark-absent`, outbox, reminder) — Hostinger shared hosting memakai cron, bukan systemd; pastikan cron `schedule:run` per menit terpasang di hPanel.
2. Mail delivery (reset/activation) dari host.
3. `/api/healthz` tetap tidak boleh diekspos publik — batasi via hPanel/`.htaccess` bila perlu.

## Backend Production (Runbook Umum)

1. Install dependency dengan committed lockfiles: `composer install --no-dev --optimize-autoloader` dan `npm ci`.
2. Buat `.env` melalui secret manager; jangan menyalin `.env` development.
3. Isi `APP_KEY`, biometric key/key ID, database, mail, session, queue, dan VAPID sesuai fitur yang digunakan.
   Gunakan `APP_TIMEZONE=Asia/Pontianak`; attendance window dan scheduler memakai timezone aplikasi ini.
4. Jalankan `php artisan migrate --force`.
5. Jalankan seeder production secara eksplisit. `UserSeeder` memang diblokir di production, tetapi jangan menjalankan seluruh demo academic seed tanpa review.
6. Build dashboard: `npm run build`.
7. Cache config/routes/views setelah seluruh env final.
8. Beri permission hanya pada `storage/` dan `bootstrap/cache/` yang diperlukan runtime.
9. Jalankan scheduler dan queue worker sebagai proses long-running yang dipantau. Pilih mekanisme sesuai OS host:
   - **Linux (production)**: install manifest `deploy/systemd/absensi-queue.service`, `absensi-schedule.service`, dan `absensi-schedule.timer`, lalu sesuaikan `/srv/absensi` serta `/etc/absensi/absensi.env` dengan host.
   - **Windows (dev/on-prem)**: `php artisan schedule:work` dijalankan oleh **Windows Task Scheduler**. Repo menyertakan `backend/schedule-worker.bat` (wrapper yang men-set path php + project lalu memanggil `schedule:work`); daftarkan sebagai task (mis. `AbsensiMahasiswaScheduler`) dengan trigger *At log on*, *restart on failure*, dan *execution time limit* unlimited agar scheduler hidup permanen tanpa perintah manual. Registrasi task memerlukan hak admin satu kali. Untuk development lokal harian cukup `php artisan serve:all` (lihat seksi Development Lokal di atas) — Windows Task Scheduler hanya untuk skenario on-prem permanen.
   Scheduler inilah yang memicu `attendance:auto-close` dan `attendance:mark-absent` (keduanya `everyMinute`), reminder, notification outbox, dan backup. Tanpa scheduler yang hidup, ALPHA dan auto-close tidak akan pernah tercatat.
10. Gunakan `/api/health` sebagai public liveness. Batasi `/api/healthz` ke operator/internal network.

Foto enrollment/re-enrollment dan dokumen izin tidak dipublikasikan melalui `storage:link`. Endpoint private controller adalah access path resminya. `storage:link` hanya boleh digunakan untuk asset yang memang diklasifikasikan publik.

## Environment Minimum

Production harus menggunakan:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://absensi.yusrilekamahendra.com
APP_TIMEZONE=Asia/Pontianak
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
DB_CONNECTION=mysql
QUEUE_CONNECTION=database
BIOMETRIC_ALLOW_CLIENT_CLAIMS=false
```

Session cookie bersifat fail-closed di production (M-21): bila `SESSION_SECURE_COOKIE` tidak diset, aplikasi tetap memaksa cookie `Secure` + `HttpOnly` dan `SameSite` minimal `lax`. Jangan menyetel `SESSION_SECURE_COOKIE=false` di production; itu mematikan proteksi HTTPS-only cookie. `SameSite=none` hanya boleh dipakai bila memang lintas situs dan otomatis dipasangkan dengan `Secure`.

Mail delivery adalah dependency keamanan untuk reset/activation. Verifikasi pengiriman sebelum provisioning user.

Lifecycle FCM mobile (register/refresh/revoke + handler) sudah diimplementasikan. Release default mematikan FCM melalui `ENABLE_FCM_PUSH=false`; aplikasi tidak mengklaim atau mencoba push tanpa konfigurasi. Untuk mengaktifkan push, inject `google-services.json` dari secret manager sebelum build, set protected variable `ENABLE_FCM_PUSH=true`, dan isi `FIREBASE_PROJECT_ID`/`FIREBASE_CREDENTIALS_PATH` pada backend. Workflow fail-closed bila FCM diaktifkan tanpa file konfigurasi. Service account JSON tetap private dan tidak boleh masuk repository.

Attendance/enrollment berbasis client scalar dikontain fail-closed di production. `BIOMETRIC_ALLOW_CLIENT_CLAIMS` wajib `false`; endpoint permit, check-in/out, offline sync, enrollment/re-enrollment, dan approval biometrik mengembalikan `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`. Nilai `true` hanya untuk local/testing compatibility dan tidak boleh dipakai sebagai production workaround. Trusted verifier server-side (challenge-bound capture, matching/liveness server, attestation) **di luar scope penelitian** dan ditolak di [ADR-001](ADR-001-trusted-biometric-verifier.md); untuk konteks penelitian containment ini permanen. Aktivasi kembali endpoint biometrik production memerlukan **keputusan kenaikan ke produksi** yang menghidupkan ulang ADR-001 beserta verifier, sesuai `THREAT-MODEL-ATTENDANCE.md`.

## Android Release

Workflow manual `.github/workflows/android-release.yml` membutuhkan protected environment `production` dan konfigurasi berikut:

| Name | Type |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | GitHub secret |
| `ANDROID_KEYSTORE_PASSWORD` | GitHub secret |
| `ANDROID_KEY_ALIAS` | GitHub secret |
| `ANDROID_KEY_PASSWORD` | GitHub secret |
| `GOOGLE_SERVICES_JSON_BASE64` | GitHub secret; wajib hanya bila `ENABLE_FCM_PUSH=true` |
| `API_BASE_URL` | Protected GitHub variable, HTTPS dan berakhiran `/api` |
| `ENABLE_FCM_PUSH` | Protected GitHub variable; default `false`, `true` hanya setelah config di-inject |

Gradle juga mendukung untracked `android/key.properties` untuk build operator lokal. Release tidak pernah fallback ke debug signing.

Build lokal:

```powershell
flutter build appbundle --release --dart-define=API_BASE_URL=https://absensi.yusrilekamahendra.com/api
```

Verifikasi certificate signer, app startup, login, checkout navigation, offline recovery, camera, dan GPS pada physical Android device sebelum distribusi. Permit/check-in/out production tetap diblokir permanen untuk konteks penelitian (trusted verifier di luar scope — [ADR-001](ADR-001-trusted-biometric-verifier.md) ditolak).

## iOS

Keputusan release H-17: **iOS tidak didukung dan tidak termasuk release matrix**. Folder `frontend/ios` dipertahankan hanya sebagai scaffold pengembangan, bukan artifact yang boleh diterbitkan. Tidak ada workflow IPA/TestFlight, signing, capability, atau dukungan operasional iOS. Platform mobile produksi satu-satunya adalah Android. Membuka dukungan iOS di masa depan memerlukan keputusan release baru, Podfile lock, macOS CI build, signing/capability, Firebase/APNs bila push diaktifkan, serta physical-iPhone camera/GPS test.

## Continuous Integration

Backend/frontend workflow dikonfigurasi pada setiap `push`/`pull_request`; release/device workflow dijalankan manual:

| Workflow | Isi |
|---|---|
| `.github/workflows/backend-ci.yml` | MySQL service, `composer validate --strict`, `check-platform-reqs`, `composer audit`, `npm ci` + `npm run build`, `php artisan test` |
| `.github/workflows/frontend-ci.yml` | Flutter `3.44.2`, enforced lockfile, analyzer strict, dan `flutter test` |
| `.github/workflows/android-device-tests.yml` | Manual Firebase Test Lab physical low/mid/high matrix untuk camera converter contract |

Analyzer warning maupun info menjadi CI failure (L-05). `android-release.yml` (manual `workflow_dispatch`) memakai gate analyzer strict yang sama sebelum membangun AAB. Device-test workflow memerlukan environment `device-testing`, secret `FIREBASE_TEST_LAB_CREDENTIALS_JSON`, serta variables `FIREBASE_PROJECT_ID`, `FIREBASE_TEST_RESULTS_BUCKET`, dan tiga device spec `FIREBASE_ANDROID_DEVICE_LOW/MID/HIGH` dalam format `model=...,version=...,locale=id,orientation=portrait`.

> Definisi workflow bukan bukti enforcement. Sebelum release, buktikan latest Backend
> CI dan Frontend CI green pada clean clone, branch `main` mewajibkan checks tersebut,
> environment `production`/`device-testing` protected, dan workflow manual terkait
> berhasil. Sampai itu tersedia, L-09 tetap terbuka.

Seluruh pekerjaan lokal sudah di-push ke `origin/main`; push terakhir 12 September 2026 (`840082b` — test invarian guard route, runner k6 R-02, scaffold Playwright E2E, pembersihan dead code Flutter) memicu workflow push/PR. **Push hanya memicu workflow, bukan membuktikan
hasilnya** — status green tetap harus diperiksa langsung di GitHub karena
GitHub CLI tidak tersedia di workspace pengembangan.

## Master Data Lifecycle dan Migration

- Rekam akademik historis memakai FK `ON DELETE RESTRICT` (M-19). Hard delete master (user/jadwal/mata_kuliah/semester) akan ditolak database selama masih ada riwayat; gunakan arsip (soft delete) atau flag `status`.
- Migration constraint domain (M-20) dan restrict lifecycle (M-19) bersifat idempotent dan reversible; `migrate`, `migrate:rollback`, dan `migrate:fresh` sudah diverifikasi pada MySQL 8.
- Sebelum memasang constraint pada dataset lama, pastikan data existing bersih (koordinat/urutan waktu/threshold valid, tanpa duplikat MK `kelas` NULL).

## Preflight dan Rollback

- Jalankan `composer validate --strict`, `composer check-platform-reqs`, backend tests, web build, Flutter tests, dan `flutter analyze --fatal-warnings --fatal-infos`.
- Backup database dan private storage sebelum migration production.
- Simpan key biometrik lama selama masih ada row dengan key ID tersebut.
- Rollback application dan database harus mempertahankan kemampuan decrypt data. Migration purge biometrik tidak boleh di-rollback secara destruktif.
- Pantau queue failure, scheduler, mail activation, auth rejection, dan attendance permit rejection setelah deploy.

Deploy berdasarkan commit SHA/tag ke direktori release baru, lalu pindahkan symlink `/srv/absensi/current` secara atomik. Setelah symlink berubah: jalankan `php artisan migrate --force`, `php artisan optimize`, `php artisan queue:restart`, restart `absensi-queue`, dan cek `/api/health`. Rollback aplikasi dilakukan dengan mengembalikan symlink ke release sebelumnya lalu mengulangi restart/health check. Migration hanya boleh di-rollback bila migration release tersebut eksplisit reversible dan tidak menghapus data; jika tidak, pertahankan schema forward-compatible dan rollback aplikasi saja.

Backup wajib mencakup database dan `storage/app/private`, `storage/app/face`, serta keyring biometrik di secret manager. Restore drill harus dilakukan ke host/database terisolasi dan membuktikan login, decrypt embedding, private file download berpolicy, queue, scheduler, dan health sebelum backup dianggap valid.
