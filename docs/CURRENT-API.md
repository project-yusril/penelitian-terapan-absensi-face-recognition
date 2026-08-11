# Kontrak API Saat Ini

**Status:** maintained summary  
**Pembaruan:** 11 Agustus 2026
**Authority:** `backend/routes/api.php`, request validation, services, dan feature tests  
**Base path:** `/api`

Jika contoh PRD atau task plan berbeda dari dokumen ini, executable routes/validation dan dokumen ini yang berlaku.

## Aturan Umum

- Protected API memakai `Authorization: Bearer <sanctum-token>`.
- Semua protected route juga memeriksa akun aktif.
- Response validation menggunakan HTTP 422; authentication 401; authorization/status akun 403; replay/conflict 409.
- Production wajib HTTPS. Mobile memperoleh base URL dari `--dart-define=API_BASE_URL=https://host/api`.

## Authentication

### `POST /auth/forgot-password`

Request:

```json
{ "email": "user@example.ac.id" }
```

Response selalu generik dan tidak pernah berisi token, baik email terdaftar maupun tidak. Token hanya dikirim melalui kanal email terverifikasi.

### `POST /auth/reset-password`

```json
{
  "email": "user@example.ac.id",
  "token": "one-time-token",
  "password": "password-baru-kuat",
  "password_confirmation": "password-baru-kuat"
}
```

Token single-use dan expiring. Reset mencabut token/session lama. Akun hanya otomatis aktif jika sebelumnya `activation_pending=true`.

## Push Notification (FCM)

### `POST /fcm-token`

Protected (semua role terautentikasi). Berada di luar prefix `/auth`.

```json
{ "fcm_token": "<device-registration-token>" }
```

- Validasi: `present|nullable|string|max:512`.
- `fcm_token` non-kosong menyimpan token perangkat sebagai target push milik user.
- `fcm_token` **string kosong atau `null` berarti revoke**: backend mengosongkan `users.fcm_token` sehingga perangkat tidak lagi menerima push. Mobile mengirim ini saat logout/sesi invalid (penting untuk perangkat bersama, C-06).
- Response 200 generik (`FCM token updated` / `FCM token cleared`).

Lifecycle klien (register saat login/startup, refresh, revoke saat logout) diimplementasikan di mobile dan bersifat fail-safe bila Firebase belum dikonfigurasi. Pengiriman push nyata memerlukan Firebase project dan `FIREBASE_CREDENTIALS_PATH` di backend. Lihat L-02 di `temuan.md`.

## Private Files

Endpoint private berada di protected group dan tetap membutuhkan signed URL:

- `GET /private/enrollment-photos/{user}`
- `GET /private/re-enrollment-photos/{reEnrollment}`
- `GET /private/leave-documents/{leaveRequest}`

Tidak ada anonymous enrollment-photo endpoint.

## Attendance Permit

### `POST /mahasiswa/attendance/permits`

```json
{
  "jadwal_id": 5,
  "action": "check_in",
  "client_uuid": "550e8400-e29b-41d4-a716-446655440000"
}
```

Untuk checkout, gunakan `action: check_out` dan sertakan `attendance_id`.

Response 201 memuat:

```json
{
  "data": {
    "permit_token": "64-character-secret",
    "liveness_challenge": "smile",
    "not_before": "2026-07-20T08:45:00+07:00",
    "expires_at": "2026-07-20T11:15:00+07:00",
    "sync_expires_at": "2026-07-20T11:45:00+07:00",
    "server_time": "2026-07-20T10:00:00+07:00"
  }
}
```

Token hanya boleh digunakan sekali dan terikat user, schedule, course, action, attendance, occurrence date, dan UUID.

> **Production containment:** kontrak permit/attendance scalar di bawah hanya aktif
> untuk local/testing compatibility. Production selalu mengembalikan
> `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED` sampai challenge-bound capture
> artifact diverifikasi server/trusted verifier. `BIOMETRIC_ALLOW_CLIENT_CLAIMS`
> tidak dapat membuka bypass di production.

Containment yang sama berlaku untuk:

- permit attendance;
- online check-in/check-out dan mixed offline sync;
- enrollment, duplicate probe, re-enrollment, dan reference embedding;
- approval enrollment/re-enrollment pada API maupun web.

Read-only status/history yang tidak membuat atau mengaktifkan evidence biometrik tetap mengikuti authentication/authorization normal.

## Online Check-in/Checkout

`POST /mahasiswa/attendance/check-in` dan `POST /mahasiswa/attendance/check-out` wajib membawa:

- `permit_token`
- `client_uuid`
- `jadwal_id`
- `attendance_id` untuk checkout
- latitude/longitude dan mock-location flag
- face distance
- liveness result dan challenge yang diberikan permit
- optional telemetry seperti GPS accuracy, inference time, device, dan app version

Online capture time berasal dari server. Client timestamp tidak dapat memperluas attendance window.

### Aturan Face Matching dan Lokasi (canonical)

- Face match canonical: `face_distance <= face_threshold`. Server menolak bila `face_distance > face_threshold`. Comparator ini identik di mobile, backend, dan analisis FAR/FRR (L-08/R-04).
- `face_threshold` diambil dari `prodi_settings.face_threshold` (default `1.000`) dan ikut dikirim ke mobile saat enrollment agar ambang sama.
- GPS accuracy: server menolak fix yang `gps_accuracy` melebihi `prodi_settings.gps_accuracy_minimum` (default `20` m) atau `location_age_ms` melebihi `gps_max_age_seconds`. Pre-check UI mobile memakai baseline yang sama (20 m); nilai per-prodi server tetap otoritatif.

## Offline Mixed Batch

### `POST /mahasiswa/attendance/sync-offline`

```json
{
  "attendances": [
    {
      "client_uuid": "550e8400-e29b-41d4-a716-446655440001",
      "jadwal_id": 5,
      "type": "check_in",
      "timestamp": "2026-07-20T09:05:00+07:00",
      "permit_token": "64-character-secret",
      "latitude": -0.0263,
      "longitude": 109.3425,
      "face_distance": 0.72,
      "liveness_passed": true,
      "liveness_challenge": "smile",
      "mock_location_detected": false
    },
    {
      "client_uuid": "550e8400-e29b-41d4-a716-446655440002",
      "jadwal_id": 8,
      "attendance_id": 321,
      "type": "check_out",
      "timestamp": "2026-07-20T10:50:00+07:00",
      "permit_token": "another-64-character-secret",
      "latitude": -0.0263,
      "longitude": 109.3425,
      "face_distance": 0.68,
      "liveness_passed": true,
      "liveness_challenge": "blink",
      "mock_location_detected": false
    }
  ]
}
```

`type` menggunakan underscore: `check_in` atau `check_out`, bukan hyphen. Maksimum 20 item. Setiap item memerlukan permit dan UUID berbeda. Hasil dipetakan per `client_uuid`.

## Analisis dan Evaluasi Penelitian

Seluruh endpoint di bawah berada pada group `admin` dan hanya untuk role
manajemen; halaman web `/analysis` dibatasi `super_admin`.

| Endpoint | Isi |
|---|---|
| `GET /admin/analysis/geofence` | Success rate dan distribusi jarak geofence |
| `GET /admin/analysis/face-verification` | Distribusi distance, FAR/FRR, sweep θ, EER, θ optimal |
| `GET /admin/analysis/latency` | Statistik dan percentile latensi, agregasi per device |
| `GET /admin/analysis/attendance-sp` | Distribusi status kehadiran, SP, dan trend mingguan |
| `GET /admin/analysis/simultaneous-test` | Hasil uji simultan per concurrent level |
| `GET /admin/analysis/conventional-comparison` | Perbandingan pencatatan konvensional vs sistem |

### Parameter `prodi_id` (canonical — R-04)

- `prodi_id` mempersempit **dataset**, bukan hanya memilih `face_threshold`. Sebelum R-04, filter hanya mengganti ambang sementara dataset genuine/impostor tetap global sehingga setiap prodi menghasilkan FAR/FRR yang identik.
- Atribusi memakai **prodi subjek** (`users.prodi_id`), sama dengan sumber ambang runtime `ProdiSetting::where('prodi_id', $user->prodi_id)`.
- `prodi_id` yang tidak dikenal menghasilkan `422` dengan error validasi pada field `prodi_id`, bukan dataset kosong.
- Mahasiswa terarsip (soft delete) tetap dihitung, sehingga hasil berfilter tidak kehilangan baris yang ikut terhitung tanpa filter.
- Tanpa `prodi_id`, seluruh prodi digabung. Angka gabungan tidak boleh dilaporkan sebagai hasil satu prodi.
- Pada `face-verification`, `threshold` eksplisit tetap mengalahkan threshold prodi dan ditandai `test_data.threshold_source = "manual"`.

### Definisi success rate geofence (canonical — R-01)

- `total_attempts` dan `success` dihitung dari action `checkin_success` versus `checkin_failed`.
- `geofence_valid` **bukan** keberhasilan: itu hanya berarti satu langkah pemeriksaan lolos, sedangkan check-in masih dapat gagal setelahnya pada face atau liveness.
- Distribusi jarak tetap berasal dari log `geofence_valid`/`geofence_invalid` karena hanya di sana `distance_to_geofence` tercatat.
- Definisi ini identik antara halaman web dan endpoint API.

Semantik lengkap beserta implikasinya untuk laporan penelitian ada di
[PRD-07-analisis-evaluasi.md](PRD-07-analisis-evaluasi.md).

## Health

- Gunakan public `/api/health` untuk liveness sederhana.
- `/api/healthz` mengandung readiness detail dan belum boleh diekspos langsung ke internet sampai M-15 ditutup; batasi pada jaringan/operator terpercaya.

PRD-04 menyimpan katalog endpoint yang lebih luas, tetapi file ini dan executable truth mengalahkan contoh payload lama.
