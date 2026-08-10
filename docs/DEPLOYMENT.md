# Deployment dan Release

**Status:** maintained runbook  
**Pembaruan:** 9 Agustus 2026

## Baseline

- PHP 8.3.30, sesuai Composer platform.
- Laravel 13.x.
- Node.js yang kompatibel dengan Vite 8 dan committed lockfile.
- MySQL production dengan database user least-privilege.
- Flutter toolchain sesuai CI (`3.38.4`) sampai versi tersebut diubah bersama workflow.
- HTTPS wajib untuk web/API production.

## Backend Production

1. Install dependency dengan committed lockfiles: `composer install --no-dev --optimize-autoloader` dan `npm ci`.
2. Buat `.env` melalui secret manager; jangan menyalin `.env` development.
3. Isi `APP_KEY`, biometric key/key ID, database, mail, session, queue, dan VAPID sesuai fitur yang digunakan.
   Gunakan `APP_TIMEZONE=Asia/Pontianak`; attendance window dan scheduler memakai timezone aplikasi ini.
4. Jalankan `php artisan migrate --force`.
5. Jalankan seeder production secara eksplisit. `UserSeeder` memang diblokir di production, tetapi jangan menjalankan seluruh demo academic seed tanpa review.
6. Build dashboard: `npm run build`.
7. Cache config/routes/views setelah seluruh env final.
8. Beri permission hanya pada `storage/` dan `bootstrap/cache/` yang diperlukan runtime.
9. Jalankan scheduler setiap menit dan queue worker terkelola Supervisor/systemd.
10. Gunakan `/api/health` sebagai public liveness. Batasi `/api/healthz` ke operator/internal network.

Foto enrollment/re-enrollment dan dokumen izin tidak dipublikasikan melalui `storage:link`. Endpoint private controller adalah access path resminya. `storage:link` hanya boleh digunakan untuk asset yang memang diklasifikasikan publik.

## Environment Minimum

Production harus menggunakan:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://absensi.example.ac.id
APP_TIMEZONE=Asia/Pontianak
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
SESSION_HTTP_ONLY=true
SESSION_SAME_SITE=lax
DB_CONNECTION=mysql
QUEUE_CONNECTION=database
```

Session cookie bersifat fail-closed di production (M-21): bila `SESSION_SECURE_COOKIE` tidak diset, aplikasi tetap memaksa cookie `Secure` + `HttpOnly` dan `SameSite` minimal `lax`. Jangan menyetel `SESSION_SECURE_COOKIE=false` di production; itu mematikan proteksi HTTPS-only cookie. `SameSite=none` hanya boleh dipakai bila memang lintas situs dan otomatis dipasangkan dengan `Secure`.

Mail delivery adalah dependency keamanan untuk reset/activation. Verifikasi pengiriman sebelum provisioning user.

`FIREBASE_PROJECT_ID` dan `FIREBASE_CREDENTIALS_PATH` bersifat opsional sampai L-02 ditutup. Jika FCM diaktifkan, credential JSON harus berada di private storage dengan permission minimum dan dikelola sebagai secret, bukan disimpan dalam repository.

## Android Release

Workflow `.github/workflows/android-release.yml` membutuhkan:

| Name | Type |
|---|---|
| `ANDROID_KEYSTORE_BASE64` | GitHub secret |
| `ANDROID_KEYSTORE_PASSWORD` | GitHub secret |
| `ANDROID_KEY_ALIAS` | GitHub secret |
| `ANDROID_KEY_PASSWORD` | GitHub secret |
| `API_BASE_URL` | Protected GitHub variable, HTTPS dan berakhiran `/api` |

Gradle juga mendukung untracked `android/key.properties` untuk build operator lokal. Release tidak pernah fallback ke debug signing.

Build lokal:

```powershell
flutter build appbundle --release --dart-define=API_BASE_URL=https://api.example.ac.id/api
```

Verifikasi certificate signer, app startup, login, permit, check-in, checkout, offline recovery, camera, dan GPS pada physical Android device sebelum distribusi.

## iOS

Info.plist, BGRA camera path, device metadata, dan Podfile telah tersedia. iOS belum masuk release matrix terverifikasi sampai langkah berikut lulus pada macOS:

```bash
flutter pub get
cd ios && pod install --repo-update && cd ..
flutter build ios --no-codesign --dart-define=API_BASE_URL=https://api.example.ac.id/api
```

Selanjutnya diperlukan signing/capability setup dan smoke test pada physical iPhone. Sampai itu selesai, dokumentasi produk harus menyebut Android sebagai platform release yang aktif.

## Continuous Integration

Dua workflow berjalan pada setiap `push`/`pull_request`:

| Workflow | Isi |
|---|---|
| `.github/workflows/backend-ci.yml` | MySQL service, `composer validate --strict`, `check-platform-reqs`, `composer audit`, `npm ci` + `npm run build`, `php artisan test` |
| `.github/workflows/frontend-ci.yml` | `flutter pub get`, `flutter analyze --fatal-warnings --fatal-infos`, `flutter test` |

Analyzer warning maupun info menjadi CI failure (L-05). `android-release.yml` (manual `workflow_dispatch`) memakai gate analyzer strict yang sama sebelum membangun AAB.

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
