# Kontrak API Saat Ini

**Status:** maintained summary  
**Pembaruan:** 18 Agustus 2026
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
> `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`. Challenge-bound capture artifact yang
> diverifikasi server (trusted verifier) **di luar scope penelitian** — rancangan
> ditolak di [ADR-001](ADR-001-trusted-biometric-verifier.md), sehingga containment
> ini bersifat permanen untuk konteks penelitian dan hanya dibuka bila proyek
> dinaikkan ke produksi. `BIOMETRIC_ALLOW_CLIENT_CLAIMS` tidak dapat membuka bypass di production.

Containment yang sama berlaku untuk:

- permit attendance;
- online check-in/check-out dan mixed offline sync;
- enrollment, duplicate probe, re-enrollment, dan reference embedding;
- approval enrollment/re-enrollment pada API maupun web.

Read-only status/history yang tidak membuat atau mengaktifkan evidence biometrik tetap mengikuti authentication/authorization normal.

### `POST /mahasiswa/enrollment/check-duplicate`

Preflight enrollment ini hanya dapat dipanggil mahasiswa terautentikasi, memakai limiter biometrik per pengguna/IP, dan mengembalikan `Cache-Control: private, no-store`. Wajah yang belum terdaftar menghasilkan `200 {"is_duplicate": false}`. Jika embedding paling dekat cocok dengan akun aktif pada prodi yang sama dan embedding berstatus `pending` atau `approved`, respons `409 BIOMETRIC_CONFLICT` menambahkan hanya nama tampilan pemilik:

```json
{
  "code": "BIOMETRIC_CONFLICT",
  "message": "Data biometrik tidak dapat digunakan untuk pendaftaran.",
  "matched_name": "Yusril",
  "logout_required": false
}
```

Respons tidak mengirim NIM, kelas, user ID, distance, threshold, atau embedding. Wajah akun nonaktif, soft-deleted, atau prodi lain tidak digunakan sebagai identity oracle. Backend menghitung konflik berdasarkan token Sanctum/session aktif dan mengubah `logout_required` menjadi `true` pada konflik ketiga, termasuk bila aplikasi direstart; token atau web session langsung dicabut server-side. Mobile menampilkan nama tersebut untuk membantu pengguna perangkat bersama dan menjalankan cleanup logout lokal saat flag aktif. Login ulang membuat token/counter baru sehingga pengguna dapat mengulang enrollment dengan wajah pemilik akun yang benar.

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

## Izin/Sakit (Leave Request)

### `POST /mahasiswa/leave-requests`

Multipart (`file_surat` opsional, `jpg|jpeg|png|pdf`, maksimum 5 MB). Field
bersama untuk kedua mode: `jenis` (`izin`/`sakit`), `tanggal_mulai`
(`after_or_equal:today`), `tanggal_selesai` (`after_or_equal:tanggal_mulai`),
dan `keterangan` (maksimum 500 karakter).

Pemilihan mata kuliah memakai salah satu dari tiga bentuk:

| Mode | Field | Perilaku |
|---|---|---|
| Single (kontrak lama) | `mata_kuliah_id` | Wajib bila dua field di bawah tidak dikirim. Tidak menyaring jadwal |
| Semua MK | `all_mata_kuliah=true` | Fan-out ke seluruh MK KRS aktif pada periode akademik aktif yang punya jadwal aktif pada rentang |
| Subset MK | `mata_kuliah_ids[]` | Fan-out ke MK yang disebut; tetap disaring periode, status, jadwal, dan keanggotaan |

Aturan resolusi mode multi:

- Target hanya MK yang mahasiswa **enrolled**, MK/semester/tahun ajarannya berstatus
  `aktif`, seluruh rentang pengajuan berada di dalam periode semester dan tahun
  ajaran, serta punya `Jadwal` berstatus `aktif` pada salah satu hari yang tercakup
  `tanggal_mulai..tanggal_selesai`. Subset eksplisit yang tidak memenuhi periode
  atau status menghasilkan `422` tanpa membuat baris.
- MK yang sudah punya izin `pending`/`approved` dengan rentang tanggal yang
  **beririsan** (`tanggal_mulai ≤ selesai baru` dan `tanggal_selesai ≥ mulai
  baru`) dilewati, bukan menggagalkan seluruh pengajuan. Aturan overlap ini juga
  berlaku pada mode single (dua izin multi-hari yang bertumpuk untuk MK yang sama
  ditolak sebagai duplikat).
- Seluruh baris dibuat dalam satu transaksi dan berbagi satu `file_surat`. Bila
  transaksi gagal, file yang telanjur diunggah ikut dihapus. Cek duplikat diulang
  di dalam lock transaksi sehingga submit paralel tidak menghasilkan izin ganda.
- `mata_kuliah_ids[]` yang memuat MK di luar enrollment menghasilkan `403` dan
  tidak membuat baris apa pun.
- Model data tetap **satu baris per mata kuliah**. Alpha, SP, dan rekap tidak
  berubah karena semuanya tetap dihitung per MK.

Response mode single tetap satu objek `LeaveRequest` (201), seperti sebelumnya.
Response mode multi (201):

```json
{
  "success": true,
  "message": "Pengajuan izin dibuat untuk 2 mata kuliah",
  "data": {
    "created_count": 2,
    "leave_requests": [{ "id": 41, "mata_kuliah_id": 11, "status": "pending" }],
    "skipped": [
      {
        "mata_kuliah_id": 13,
        "nama": "Statistika",
        "alasan": "tanpa_jadwal",
        "pesan": "Tidak ada jadwal aktif pada rentang tanggal"
      }
    ]
  }
}
```

`alasan` bernilai `duplikat` atau `tanpa_jadwal`. Bila tidak ada satu pun baris
yang dapat dibuat, response `422`; pada mode multi ringkasan `skipped` yang sama
dikirim di `errors.skipped`, termasuk ketika semua target tersaring cek-ulang di
dalam lock transaksi (submit paralel).

Approval tidak berubah: `PUT /kaprodi/leave-requests/{id}/approve` bekerja
per baris, sehingga menyetujui satu izin hanya memengaruhi attendance mata
kuliah tersebut.

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

### Scope aktor pada endpoint analisis (M-24)

Endpoint ini terbuka untuk `super_admin`, `admin_jurusan`, dan `admin_prodi`,
sehingga `prodi_id` tidak boleh dipercaya apa adanya. Filter request hanya dapat
**mempersempit** scope, tidak pernah memperluasnya:

| Aktor | Perilaku |
|---|---|
| `super_admin` | `prodi_id` request dipakai apa adanya; tanpa filter berarti gabungan seluruh prodi |
| `admin_jurusan`, `admin_prodi` | Dipaksa ke `prodi_id` aktor. Meminta prodi lain menghasilkan `403` |
| Aktor tingkat prodi tanpa `prodi_id` | `403` (fail-closed, tidak jatuh ke dataset global) |

Matriks role lengkap ada di [ROLE-PERMISSION-MATRIX.md](ROLE-PERMISSION-MATRIX.md).

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
