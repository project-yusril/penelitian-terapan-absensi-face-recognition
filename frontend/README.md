# Mobile Absensi Mahasiswa

Aplikasi Flutter Android mahasiswa untuk enrollment biometrik, attendance permit, check-in/check-out, offline queue, riwayat, izin, dan status SP. Trusted biometric verifier berada di luar scope release penelitian ini sehingga production attendance/enrollment tetap fail-closed; detail keputusan ada di [ADR-001](../docs/ADR-001-trusted-biometric-verifier.md).

## Konfigurasi API

`API_BASE_URL` wajib diberikan saat run/build:

```powershell
flutter run --dart-define=API_BASE_URL=https://api.example.ac.id/api
```

Release/profile hanya menerima HTTPS. Debug HTTP menerima loopback, alias emulator `10.0.2.2`, dan alamat privat RFC 1918 untuk pengujian perangkat fisik. LAN HTTP hanya boleh dipakai dengan akun/data uji pada hotspot atau router pribadi yang dipercaya karena trafik tidak terenkripsi. Panduan backend, firewall, build APK Wi-Fi, dan fallback USB tersedia di [README project](../README.md#menjalankan-aplikasi-melalui-wi-fi).

## Platform

| Platform | Status |
|---|---|
| Android | Platform release aktif |
| iOS | Tidak didukung; folder platform hanya scaffold pengembangan dan bukan release target |

## Attendance Flow

1. Aplikasi meminta permit dari server. Production release penelitian mengembalikan `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED` karena trusted verifier tidak diimplementasikan dalam scope ini.
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
flutter pub get --enforce-lockfile
flutter test
flutter analyze --fatal-warnings --fatal-infos
```

Physical-device verification tetap diperlukan untuk camera format, GPS/mock-location behavior, check-in/check-out, dan offline restart.

FCM adalah explicit release opt-in. Default `ENABLE_FCM_PUSH=false`; build release hanya boleh memakai `true` bila Firebase configuration diinjeksi oleh secret manager. Detail ada di [deployment runbook](../docs/DEPLOYMENT.md).

## Release

Android release signing fail-closed tanpa keystore configuration. Lihat [deployment runbook](../docs/DEPLOYMENT.md) dan [Android release workflow](../.github/workflows/android-release.yml).
