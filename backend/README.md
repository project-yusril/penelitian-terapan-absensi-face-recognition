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
- Node.js kompatibel dengan Vite 8
- MySQL untuk target production

## Setup Lokal

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm install
composer dev
```

Isi biometric encryption key, database, dan mail sebelum menguji enrollment/activation. Jangan menggunakan key atau `.env` production di local environment.

## Commands

| Command | Fungsi |
|---|---|
| `composer dev` | Menjalankan server, queue listener, log viewer, dan Vite |
| `composer test` | Membersihkan config cache dan menjalankan backend tests |
| `npm run build` | Build asset Inertia/Vue production |
| `php artisan migrate` | Menjalankan migration authoritative |
| `php artisan schedule:list` | Memeriksa scheduled tasks |

## Security Notes

- Protected API menggunakan Sanctum dan active-user middleware.
- Password reset token tidak pernah dikembalikan melalui API.
- Production tidak menjalankan `UserSeeder`.
- Biometric key harus terpisah dari `APP_KEY` dan dikelola sebagai secret.
- Enrollment/leave files private; jangan mengekspos storage path sebagai public URL.
