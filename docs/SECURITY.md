# Model Keamanan

**Pembaruan:** 14 September 2026
**Backlog risiko:** [`temuan.md`](temuan.md)
**Threat model attendance:** [`THREAT-MODEL-ATTENDANCE.md`](THREAT-MODEL-ATTENDANCE.md)

## Kontrol Aktif

- Sanctum bearer token untuk mobile dan session authentication untuk web.
- `user.active` pada seluruh protected route; deactivation mencabut token dan database session.
- Object-level authorization dan prodi scope pada mutation sensitif yang telah ditutup di audit. Matriks role/permission/prodi canonical beserta tiga lapis enforcement ada di [ROLE-PERMISSION-MATRIX.md](ROLE-PERMISSION-MATRIX.md) (MS-01).
- Endpoint analisis penelitian memakai scope aktor: role tingkat prodi dipaksa ke `prodi_id` sendiri dan fail-closed tanpa prodi, sehingga statistik prodi lain tidak terbaca (M-24).
- Forgot-password generic/non-enumerating; token hanya melalui email, single-use, expiring, dan reset mencabut credential lama.
- Tidak ada password universal. Import/provisioning menggunakan random placeholder dan one-time activation.
- Attendance permit sekali pakai, short-lived, dan bound ke resource/action/UUID.
- Queue offline terenkripsi dan terisolasi per user dengan stale lease recovery.
- FCM token dicabut (device `deleteToken` + backend `POST /fcm-token` kosong) saat logout dan sesi invalid, sehingga perangkat bersama tidak menerima push milik akun sebelumnya (L-02/C-06).
- Face embedding terenkripsi menggunakan key biometrik terpisah.
- Biometric/medical files private dan diakses melalui authenticated signed route.
- Android release fail-closed tanpa release signing secrets.
- Throttle login web (`throttle:login`, 5/menit per IP+identitas, 30/menit per IP) dan TOTP verify (`throttle:5,1`).
- Seluruh group API terautentikasi memakai `throttle:api` (60/menit per user) dan `POST /auth/change-password` memakai `throttle:auth-sensitive` (5/menit per user) karena memverifikasi `current_password`. Keying per user, bukan per IP, agar NAT kampus tidak saling mengunci (M-23).
- Ganti password web mencabut seluruh Sanctum token dan session lain milik user, lalu me-regenerate sesi aktif (M-21).
- Session cookie fail-closed di production: `AppServiceProvider` memaksa `secure`+`http_only` dan `same_site` minimal `lax` saat env cookie tidak diset; `SameSite=none` otomatis dipasangkan dengan `Secure` (M-21).
- Rekam akademik historis dilindungi FK `ON DELETE RESTRICT`; hard delete master ditolak database selama ada riwayat, arsip via soft delete (M-19).
- Invariant domain ditegakkan database via CHECK/UNIQUE/composite index sebagai lapisan terakhir terhadap import/race/script (M-20).
- CI gate: `flutter analyze --fatal-warnings --fatal-infos` + `flutter test` (Frontend CI) dan backend test/validate/audit (Backend CI) berjalan pada setiap push/PR.
- Sejak 21 September 2026 (keputusan pemilik proyek, revisi ADR-001): `BIOMETRIC_ALLOW_CLIENT_CLAIMS=true` di production membuka endpoint biometrik dengan bukti **client-attested**. Tanpa flag, gate tetap fail-closed 503. Lihat Residual Risk Utama.

## Residual Risk Utama

- **Trusted verifier server-side (C-04/H-04) di luar scope penelitian — residual risk diterima.** Legacy implementation menghitung koordinat, face distance, liveness, dan embedding dari client; face matching berjalan on-device dan server tidak mengulangnya. Rancangan verifier server-side ditinjau di [ADR-001](ADR-001-trusted-biometric-verifier.md) dan **ditolak** karena di luar kebutuhan penelitian. Sejak 21 September 2026, produksi **menerima** bukti client-attested (`BIOMETRIC_ALLOW_CLIENT_CLAIMS=true`, keputusan pemilik proyek untuk demo penelitian), sehingga sistem **tidak boleh diklaim tahan proxy attendance atau presentation attack** — klaim ini tidak pernah berlaku dan tetap dilarang. Menaikkan proyek ke produksi nyata mengharuskan C-04/H-04 dibuka kembali. Lihat C-04/H-04 di [temuan.md](temuan.md).
- iOS tidak didukung dan tidak termasuk release matrix. Lihat H-17.
- Readiness detail `/healthz` harus dibatasi. Lihat M-15.
- FCM mobile memiliki token lifecycle dan handler lengkap, tetapi release default `ENABLE_FCM_PUSH=false`; opt-in mewajibkan konfigurasi Firebase yang diinjeksi secret manager. Lihat L-02.
- `BIOMETRIC_ALLOW_CLIENT_CLAIMS=true` aktif di production sejak 21 September 2026 (keputusan pemilik proyek); mematikan flag mengembalikan fail-closed 503.
- Camera physical-device matrix Android low/mid/high belum memiliki run evidence; lihat H-16.
- Remote CI green run, protected environment, dan required-check enforcement belum terbukti; lihat L-09.

## Secret Management

Secret berikut wajib berada di secret manager/deployment environment dan tidak boleh masuk Git, dokumentasi, artifact, atau log:

- `APP_KEY`
- `BIOMETRIC_ENCRYPTION_KEY` dan previous keyring
- database/mail credentials
- VAPID private key
- Android keystore dan passwords
- Firebase service account dan `GOOGLE_SERVICES_JSON_BASE64`
- reset token, Sanctum token, dan real biometric vectors
- SSH credential server production (key private + password) — disimpan lokal di `ssh.md` root repo (di-gitignore) dan `~/.ssh/`; tidak pernah masuk Git

Jika `.env`, key, atau credential pernah dibagikan dalam archive/repository, anggap bocor dan rotasi.

## Provisioning Akun

1. Admin membuat/import akun tanpa default password.
2. Sistem membuat random placeholder yang tidak pernah ditampilkan.
3. Akun disimpan nonaktif dengan `activation_pending=true`.
4. One-time reset/activation token dikirim ke email terverifikasi.
5. Setelah token valid digunakan, password disimpan, activation pending dibersihkan, dan akun diaktifkan.

Suspended account tidak otomatis aktif hanya karena password reset; auto-activation hanya berlaku untuk provisioning pending.

## Klaim Anti-Spoofing

Dokumentasi dan materi penelitian harus membedakan:

- face matching identity performance, seperti FAR/FRR/EER;
- presentation attack detection, seperti foto/video/replay;
- device/location integrity;
- server authorization/replay protection.

Jangan menyatakan sistem mencegah fake GPS, deepfake, atau replay secara absolut tanpa evidence terhadap threat model dan device matrix yang relevan.

## Security Release Decision

| Keputusan | Konsekuensi |
|---|---|
| Android-only | iOS tidak menerima signing, artifact, support, atau security claim |
| Biometrik client-attested diaktifkan (21 Sep 2026, keputusan pemilik proyek) | Attendance/enrollment production berfungsi dengan bukti client-attested; bukan verifier server-side; larangan klaim anti proxy/presentation attack tetap berlaku |
| FCM default off | Push hanya aktif bila release opt-in dan seluruh credential/config tersedia |
| Private storage | Enrollment/re-enrollment/izin tidak boleh dilayani melalui public symlink |

Konfigurasi dan rollback operasional berada di [DEPLOYMENT.md](DEPLOYMENT.md); status acceptance berada di [temuan.md](temuan.md).
