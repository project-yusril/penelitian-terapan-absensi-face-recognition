# Sistem Absensi Mahasiswa

Sistem absensi Jurusan Teknik Elektro Politeknik Negeri Pontianak dengan dashboard Laravel/Inertia/Vue dan aplikasi mahasiswa Flutter Android. Kontrol utama mencakup geofence, verifikasi wajah, attendance permit sekali pakai, offline queue terenkripsi, serta workflow akademik dan SP.

> **Status release 11 Agustus 2026:** belum production-ready. Endpoint mutation
> attendance dan enrollment diblokir fail-closed di production sampai trusted
> biometric verifier tersedia. Android adalah satu-satunya target mobile release;
> iOS tidak didukung. Lihat [`docs/temuan.md`](docs/temuan.md) untuk blocker.

## Dokumentasi

Mulai dari [`docs/README.md`](docs/README.md). Dokumen tersebut menjelaskan sumber kebenaran, status PRD, dokumen historis, dan referensi operasional.

Referensi utama:

- [Arsitektur saat ini](docs/CURRENT-ARCHITECTURE.md)
- [Kontrak API saat ini](docs/CURRENT-API.md)
- [Keamanan dan residual risk](docs/SECURITY.md)
- [Matriks role, permission, dan prodi](docs/ROLE-PERMISSION-MATRIX.md)
- [Deployment dan release](docs/DEPLOYMENT.md)
- [Audit dan backlog aktif](docs/temuan.md)
- [Indeks kebutuhan produk](docs/PRD-INDEX.md)

## Struktur

| Lokasi | Fungsi |
|---|---|
| `backend/` | Laravel REST API, web Inertia/Vue, queue, scheduler, dan database migrations |
| `frontend/` | Aplikasi Flutter mahasiswa untuk enrollment dan attendance |
| `docs/` | Spesifikasi, arsitektur, operasi, audit, dan arsip keputusan |
| `.github/workflows/` | Backend CI, Frontend CI, Android release, dan Android physical-device test harness |
| `deploy/` | Contoh manifest Nginx dan systemd untuk deployment Linux |

## Quick Start

Backend:

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate
npm ci
composer dev
```

Flutter, dengan backend HTTPS yang dapat dijangkau perangkat:

```powershell
cd frontend
flutter pub get --enforce-lockfile
flutter run --dart-define=API_BASE_URL=https://api.example.ac.id/api
```

Debug HTTP hanya diterima untuk `localhost`, `127.0.0.1`, atau `::1`. Build profile/release wajib menggunakan HTTPS.

## Verifikasi

```powershell
cd backend
composer test

cd ..\frontend
flutter test
flutter analyze --fatal-warnings --fatal-infos
```

Workflow CI mendefinisikan gate yang sama pada setiap push/PR: Backend CI (`composer validate`/`check-platform-reqs`/`audit`, npm audit/build, `php artisan test`) dan Frontend CI (`flutter analyze --fatal-warnings --fatal-infos`, `flutter test`). Status remote run dan required-check enforcement tetap mengikuti L-09 di `docs/temuan.md`.

Status kesiapan produksi tidak ditentukan oleh build saja. Gunakan acceptance dan blocker pada [`docs/temuan.md`](docs/temuan.md).
