# Mobile Absensi Mahasiswa

Aplikasi Flutter mahasiswa untuk enrollment biometrik, attendance permit, check-in/check-out, offline queue, riwayat, izin, dan status SP.

## Konfigurasi API

`API_BASE_URL` wajib diberikan saat run/build:

```powershell
flutter run --dart-define=API_BASE_URL=https://api.example.ac.id/api
```

Release/profile hanya menerima HTTPS. Debug HTTP hanya menerima loopback (`localhost`, `127.0.0.1`, `::1`); LAN HTTP dan `10.0.2.2` tidak diterima oleh policy saat ini.

## Platform

| Platform | Status |
|---|---|
| Android | Platform release aktif |
| iOS | Basic code path tersedia, tetapi build/signing/physical-device verification belum selesai |

## Attendance Flow

1. Aplikasi meminta permit dari server.
2. Server memeriksa academic-resource invariant dan attendance window.
3. Aplikasi memvalidasi lokasi, liveness, dan wajah.
4. Evidence dikirim bersama permit sekali pakai.
5. Jika jaringan hilang setelah permit terbit, item dapat masuk encrypted per-user queue dan disinkronkan sebelum permit expiry.

Checkout dapat dibuka dari jadwal yang sudah check-in dan belum checkout.

## Offline Queue

- Hive AES box per user.
- Key disimpan di secure storage.
- Logout mem-purge queue/key user.
- State `syncing` memakai lease lima menit dan stale recovery saat restart.
- Checkout item selalu membawa `jadwal_id` dan `attendance_id`.

## Development

```powershell
flutter pub get
flutter test
flutter analyze
```

Physical-device verification tetap diperlukan untuk camera format, GPS/mock-location behavior, check-in/check-out, dan offline restart.

## Release

Android release signing fail-closed tanpa keystore configuration. Lihat [deployment runbook](../docs/DEPLOYMENT.md) dan [Android release workflow](../.github/workflows/android-release.yml).
