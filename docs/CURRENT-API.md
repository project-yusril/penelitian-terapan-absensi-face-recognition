# Kontrak API Saat Ini

**Status:** maintained summary  
**Pembaruan:** 9 Agustus 2026  
**Authority:** `backend/routes/api.php`, request validation, services, dan feature tests  
**Base path:** `/api`

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

## Health

- Gunakan public `/api/health` untuk liveness sederhana.
- `/api/healthz` mengandung readiness detail dan belum boleh diekspos langsung ke internet sampai M-15 ditutup; batasi pada jaringan/operator terpercaya.

PRD-04 menyimpan katalog endpoint yang lebih luas, tetapi file ini dan executable truth mengalahkan contoh payload lama.
