# Model Keamanan

**Pembaruan:** 11 Agustus 2026
**Backlog risiko:** [`temuan.md`](temuan.md)
**Threat model attendance:** [`THREAT-MODEL-ATTENDANCE.md`](THREAT-MODEL-ATTENDANCE.md)

## Kontrol Aktif

- Sanctum bearer token untuk mobile dan session authentication untuk web.
- `user.active` pada seluruh protected route; deactivation mencabut token dan database session.
- Object-level authorization dan prodi scope pada mutation sensitif yang telah ditutup di audit.
- Forgot-password generic/non-enumerating; token hanya melalui email, single-use, expiring, dan reset mencabut credential lama.
- Tidak ada password universal. Import/provisioning menggunakan random placeholder dan one-time activation.
- Attendance permit sekali pakai, short-lived, dan bound ke resource/action/UUID.
- Queue offline terenkripsi dan terisolasi per user dengan stale lease recovery.
- FCM token dicabut (device `deleteToken` + backend `POST /fcm-token` kosong) saat logout dan sesi invalid, sehingga perangkat bersama tidak menerima push milik akun sebelumnya (L-02/C-06).
- Face embedding terenkripsi menggunakan key biometrik terpisah.
- Biometric/medical files private dan diakses melalui authenticated signed route.
- Android release fail-closed tanpa release signing secrets.
- Throttle login web (`throttle:login`, 5/menit per IP+identitas, 30/menit per IP) dan TOTP verify (`throttle:5,1`).
- Ganti password web mencabut seluruh Sanctum token dan session lain milik user, lalu me-regenerate sesi aktif (M-21).
- Session cookie fail-closed di production: `AppServiceProvider` memaksa `secure`+`http_only` dan `same_site` minimal `lax` saat env cookie tidak diset; `SameSite=none` otomatis dipasangkan dengan `Secure` (M-21).
- Rekam akademik historis dilindungi FK `ON DELETE RESTRICT`; hard delete master ditolak database selama ada riwayat, arsip via soft delete (M-19).
- Invariant domain ditegakkan database via CHECK/UNIQUE/composite index sebagai lapisan terakhir terhadap import/race/script (M-20).
- CI gate: `flutter analyze --fatal-warnings --fatal-infos` + `flutter test` (Frontend CI) dan backend test/validate/audit (Backend CI) berjalan pada setiap push/PR.
- Production biometric containment menolak seluruh mutation/reference/approval yang bergantung pada scalar client sampai trusted verifier tersedia.

## Residual Risk Utama

- Legacy implementation masih menghitung koordinat, face distance, liveness, dan embedding dari client, sehingga production memblokir endpoint terkait dengan `TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED` sampai trusted verifier tersedia. Lihat C-04/H-04.
- iOS tidak didukung dan tidak termasuk release matrix. Lihat H-17.
- Readiness detail `/healthz` harus dibatasi. Lihat M-15.
- FCM mobile memiliki token lifecycle dan handler lengkap, tetapi release default `ENABLE_FCM_PUSH=false`; opt-in mewajibkan konfigurasi Firebase yang diinjeksi secret manager. Lihat L-02.
- `BIOMETRIC_ALLOW_CLIENT_CLAIMS=true` hanya untuk local/testing compatibility dan dilarang di production.
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
| Biometrik production fail-closed | Tidak ada attendance/enrollment production sampai verifier tepercaya tersedia |
| FCM default off | Push hanya aktif bila release opt-in dan seluruh credential/config tersedia |
| Private storage | Enrollment/re-enrollment/izin tidak boleh dilayani melalui public symlink |

Konfigurasi dan rollback operasional berada di [DEPLOYMENT.md](DEPLOYMENT.md); status acceptance berada di [temuan.md](temuan.md).
