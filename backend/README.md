# Backend Absensi Mahasiswa

Laravel 13 application yang menyediakan REST API mobile, dashboard Inertia/Vue, queue, scheduler, private file delivery, dan domain akademik.

## Dokumentasi

- [Arsitektur](../docs/CURRENT-ARCHITECTURE.md)
- [Kontrak API](../docs/CURRENT-API.md)
- [Deployment](../docs/DEPLOYMENT.md)
- [Keamanan](../docs/SECURITY.md)
- [Audit aktif](../docs/temuan.md)

## Requirement

- PHP 8.3.30 baseline
- Composer dengan committed `composer.lock`
- Node.js `22.21.1` dan npm `11.6.2` sesuai `.nvmrc`/`package.json`
- MySQL untuk target production

## Setup Lokal

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm ci
composer dev
```

Isi biometric encryption key, database, dan mail sebelum menguji enrollment/activation. Jangan menggunakan key atau `.env` production di local environment.

`BIOMETRIC_ALLOW_CLIENT_CLAIMS=true` mengaktifkan alur biometrik client-attested di semua environment, termasuk production (revisi ADR-001, 21 September 2026 — keputusan pemilik proyek untuk demo penelitian). Bukti liveness/face-distance tetap **client-attested**, bukan verifier server-side; sistem tidak boleh diklaim tahan proxy attendance atau presentation attack. Tanpa flag, gate tetap fail-closed (`503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`). Lihat [DEPLOYMENT.md](../docs/DEPLOYMENT.md) dan [ADR-001](../docs/ADR-001-trusted-biometric-verifier.md).

## Commands

| Command | Fungsi |
|---|---|
| `composer dev` | Menjalankan server, scheduler, queue listener, log viewer, dan Vite |
| `composer test` | Membersihkan config cache dan menjalankan backend tests |
| `npm run build` | Build asset Inertia/Vue production |
| `php artisan migrate` | Menjalankan migration authoritative |
| `php artisan serve:all` | Menjalankan dev server **dan** scheduler sekaligus dari satu proses (`serve` + `schedule:work`), opsi `--host=0.0.0.0 --port=8000` |
| `php artisan schedule:list` | Memeriksa scheduled tasks |
| `php artisan schedule:work` | Menjalankan scheduler long-running saja (memicu `attendance:auto-close` & `attendance:mark-absent` tiap menit, reminder, outbox, backup) |

Untuk development lokal gunakan `php artisan serve:all` (atau `composer dev`) — satu perintah ini sudah menyalakan dev server sekaligus scheduler, sehingga ALPHA dan auto-close tercatat otomatis tanpa proses terpisah. Lihat `app/Console/Commands/ServeAll.php`.

Untuk deployment Windows on-prem, scheduler permanen dijalankan lewat Windows Task Scheduler menggunakan wrapper `schedule-worker.bat` (lihat `docs/DEPLOYMENT.md`). Di Linux gunakan cron `schedule:run` atau `schedule:work` di bawah Supervisor/systemd.

## Security Notes

- Protected API menggunakan Sanctum dan active-user middleware.
- Password reset token tidak pernah dikembalikan melalui API.
- Production tidak menjalankan `UserSeeder`.
- Biometric key harus terpisah dari `APP_KEY` dan dikelola sebagai secret.
- Enrollment/leave files private; jangan mengekspos storage path sebagai public URL.
- Logout, invalidasi sesi, dan deactivation membersihkan FCM token agar perangkat bersama tidak menerima push akun lama.
