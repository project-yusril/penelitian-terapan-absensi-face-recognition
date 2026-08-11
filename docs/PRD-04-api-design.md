# PRD-04: API DESIGN (Backend Endpoints)

> **Status 18 Juli 2026:** katalog endpoint historis yang sedang dimigrasikan.
> Kontrak security-sensitive yang authoritative ada di
> [CURRENT-API.md](CURRENT-API.md), sedangkan route authoritative ada di
> `backend/routes/api.php`. Contoh reset token, private photo, attendance direct,
> dan offline payload lama di bawah tidak boleh digunakan bila bertentangan
> dengan dokumen current.
> Endpoint attendance/enrollment yang tercantum dapat tersedia sebagai route,
> tetapi production saat ini mengembalikan `503 TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED`
> sampai trusted verifier tersedia.

> **D-01 & D-02 (disinkronkan 16 Juni 2026):** Dokumen ini telah diselaraskan
> dengan implementasi nyata di `backend/routes/api.php`. Base URL, prefix, method,
> dan path telah dikoreksi. Setiap endpoint diberi penanda status:
>
> - ✅ **Implemented** — route tersedia & dipakai.
> - 🟡 **Implemented (path berbeda dari draf lama)** — fungsionalitas ada, tetapi
>   path-nya berubah dari versi dokumen sebelumnya.
> - ❌ **Not implemented** — disebut di draf lama tetapi TIDAK ada di `routes/api.php`.

## Base URL

| Lingkungan | Base URL |
|------------|----------|
| Flutter debug loopback | `http://localhost:<port>/api` |
| Device/emulator melalui network | HTTPS endpoint yang dapat dijangkau device |
| Produksi | `https://<domain-produksi>/api` |

> Catatan: **tidak ada** segmen `/v1`. Seluruh route otomatis mendapat prefix `/api`
> (lihat `routes/api.php`). Domain `api.absensi.domain.com` pada draf lama adalah dummy.

## Authentication

- Header: `Authorization: Bearer {token}` (Laravel Sanctum).
- Token mobile diberi nama `mobile-<device_name>`; login mobile hanya menghapus
  token `mobile-%` (sesi web/panel admin tidak terputus — lihat M-04).
- Rate limit (current, M-23): seluruh group API terautentikasi memakai
  `throttle:api` (60/menit **per user**). Di atasnya, limiter khusus berlaku
  per domain: `login` untuk grup auth publik, `auth-sensitive` (5/menit) untuk
  `POST /auth/change-password`, `attendance` untuk check-in/out & sync,
  `upload`, `export` untuk export laporan, dan `biometric-probe` untuk
  enrollment/duplicate probe. Keying per user, bukan per IP, agar pengguna di
  belakang NAT kampus tidak saling mengunci. Definisinya ada di
  `AppServiceProvider::configureRateLimiting()` — Laravel 11+ tidak lagi punya
  `app/Http/Kernel.php`.

---

## 0. PUBLIC / UTILITY ENDPOINTS

| Method | Endpoint | Deskripsi | Auth | Status |
|--------|----------|-----------|------|--------|
| GET | `/health` | Health check API | Public | ✅ |
| GET | `/private/enrollment-photos/{user}` | Foto enrollment private | Sanctum + Signed + Policy | ✅ |
| GET | `/private/re-enrollment-photos/{reEnrollment}` | Foto re-enrollment private | Sanctum + Signed + Policy | ✅ |
| GET | `/private/leave-documents/{leaveRequest}` | Dokumen izin private | Sanctum + Signed + Policy | ✅ |

---

## 1. AUTH ENDPOINTS

Prefix `/auth`. Login/forgot/reset bersifat publik (throttle `login`), sisanya butuh `auth:sanctum`.

| Method | Endpoint | Deskripsi | Role | Status |
|--------|----------|-----------|------|--------|
| POST | `/auth/login` | Login (email/NIM + password) | Public | ✅ |
| POST | `/auth/forgot-password` | Request token reset password | Public | ✅ |
| POST | `/auth/reset-password` | Reset password dengan token | Public | ✅ |
| POST | `/auth/logout` | Logout (revoke current token) | All (auth) | ✅ |
| GET | `/auth/me` | Get current user profile | All (auth) | ✅ |
| POST | `/auth/refresh` | Refresh token (nama device dipertahankan, M-03) | All (auth) | ✅ |
| POST | `/auth/change-password` | Ubah password | All (auth) | 🟡 (POST, bukan PUT) |
| POST | `/fcm-token` | Update FCM token | All (auth) | 🟡 (di luar prefix `/auth`) |
| ~~PUT~~ | ~~`/auth/update-profile`~~ | Diganti `PUT /profile` | — | ❌ (lihat §1B) |
| ~~PUT~~ | ~~`/auth/update-fcm-token`~~ | Diganti `POST /fcm-token` | — | ❌ |

#### POST `/auth/login`
```json
// Request
{
    "login": "mahasiswa@email.com atau 3202316055",
    "password": "<password-user>",
    "device_name": "Samsung Galaxy A54"   // opsional → token name "mobile-<device_name>"
}

// Response 200
{
    "success": true,
    "message": "Login berhasil",
    "data": {
        "user": {
            "id": 1,
            "nama": "Muhammad Haris",
            "email": "haris@polnep.ac.id",
            "nim": "3202316055",
            "nidn": null,
            "foto_profil": null,
            "roles": ["mahasiswa"],
            "prodi": { "id": 2, "kode": "TI", "nama": "Teknik Informatika" },
            "must_change_password": false,
            "enrollment_status": "approved"
        },
        "token": "1|abc123...",
        "token_type": "Bearer"
    }
}
```

#### POST `/auth/forgot-password` → `/auth/reset-password`
```json
// POST /auth/forgot-password — Request
{ "email": "haris@polnep.ac.id" }

// Response 200 selalu generik; token TIDAK pernah dikembalikan oleh API
{
    "success": true,
    "message": "Jika email terdaftar, instruksi reset password telah dikirim."
}

// POST /auth/reset-password — Request
{
    "email": "haris@polnep.ac.id",
    "token": "xxxxx",
    "password": "passwordbaru",
    "password_confirmation": "passwordbaru"
}
```

### 1B. PROFILE ENDPOINTS

| Method | Endpoint | Deskripsi | Role | Status |
|--------|----------|-----------|------|--------|
| GET | `/profile` | Get profil lengkap | All (auth) | ✅ |
| PUT | `/profile` | Update profil | All (auth) | ✅ |
| POST | `/profile/foto` | Upload foto profil | All (auth) | ✅ |
| POST | `/profile/signature` | Upload tanda tangan (kaprodi/kajur) | All (auth) | ✅ |

---

## 2. ENROLLMENT ENDPOINTS

Submit & status oleh **Mahasiswa** (prefix `/mahasiswa`). Approval oleh **Kaprodi** (prefix `/kaprodi`).

| Method | Endpoint | Deskripsi | Role | Status |
|--------|----------|-----------|------|--------|
| POST | `/mahasiswa/enrollment` | Submit embedding wajah (192-dim, M-06) | Mahasiswa | 🟡 (dulu `/enrollment/submit`) |
| POST | `/mahasiswa/enrollment/check-duplicate` | Preflight duplicate-face check | Mahasiswa | ✅ |
| GET | `/mahasiswa/enrollment/status` | Cek status enrollment | Mahasiswa | 🟡 |
| POST | `/mahasiswa/re-enrollment` | Request re-enrollment | Mahasiswa | 🟡 (dulu `/enrollment/re-request`) |
| GET | `/mahasiswa/enrollment/embedding` | Get embedding + `face_threshold` (H-04) | Mahasiswa (enrollment.approved) | 🟡 (dulu `/enrollment/my-embedding`) |
| GET | `/kaprodi/enrollments` | List enrollment (pending dsb.) | Kaprodi | 🟡 (dulu `/enrollment/pending`) |
| PUT | `/kaprodi/enrollments/{id}/approve` | Approve enrollment | Kaprodi | 🟡 |
| PUT | `/kaprodi/enrollments/{id}/reject` | Reject enrollment | Kaprodi | 🟡 |
| GET | `/kaprodi/re-enrollments` | List re-enrollment | Kaprodi | ✅ |
| PUT | `/kaprodi/re-enrollments/{id}/approve` | Approve re-enrollment | Kaprodi | ✅ |
| PUT | `/kaprodi/re-enrollments/{id}/reject` | Reject re-enrollment | Kaprodi | ✅ |

> ❌ **`/enrollment/pending`** (draf lama) tidak ada. Gunakan `GET /kaprodi/enrollments`.

#### POST `/mahasiswa/enrollment`
```json
// Request (multipart/form-data)
{
    "embedding": [0.0234, -0.0891, 0.1456, ...],  // WAJIB tepat 192 float (size:192, M-06)
    "foto": "<file image jpeg/jpg/png, max 500KB>",
    "liveness_passed": true,
    "enrollment_device": "Samsung Galaxy A54"
}

// Response 201
{
    "success": true,
    "message": "Enrollment berhasil disubmit. Menunggu approval.",
    "data": {
        "enrollment_status": "pending",
        "message": "Pendaftaran wajah berhasil. Menunggu persetujuan Kaprodi."
    }
}
```

#### GET `/mahasiswa/enrollment/embedding` (H-04)
```json
// Response 200
{
    "success": true,
    "data": {
        "embedding": [/* 192 float */],
        "embedding_size": 192,
        "version": 1,
        "face_threshold": 1.00,     // dari ProdiSetting.face_threshold
        "liveness_required": true
    }
}
```

---

## 3. ATTENDANCE ENDPOINTS

Prefix `/mahasiswa/attendance`. Check-in/out & sync butuh middleware `enrollment.approved` + throttle `attendance`.

> Setiap check-in/out harus diawali permit. Permit memuat server time, inclusive
> capture window, sync expiry, liveness challenge, serta binding user/jadwal/
> action/attendance/UUID. Lihat contoh lengkap di [CURRENT-API.md](CURRENT-API.md).

| Method | Endpoint | Deskripsi | Role | Status |
|--------|----------|-----------|------|--------|
| POST | `/mahasiswa/attendance/permits` | Terbitkan permit one-time untuk check-in/check-out | Mahasiswa | ✅ |
| POST | `/mahasiswa/attendance/check-in` | Check-in absensi | Mahasiswa | ✅ |
| POST | `/mahasiswa/attendance/check-out` | Check-out absensi | Mahasiswa | ✅ |
| GET | `/mahasiswa/attendance/today` | Status absensi hari ini | Mahasiswa | ✅ |
| GET | `/mahasiswa/attendance/history` | Riwayat absensi | Mahasiswa | ✅ |
| POST | `/mahasiswa/attendance/sync-offline` | Sync batch absensi offline (maks 20, idempotent via `client_uuid`) | Mahasiswa | ✅ |
| ~~GET~~ | ~~`/attendance/summary`~~ | Summary kehadiran | — | ❌ (gunakan `GET /mahasiswa/dashboard`) |
| ~~GET~~ | ~~`/attendance/active-schedule`~~ | Jadwal berlangsung | — | ❌ (gunakan `GET /mahasiswa/jadwal/active`) |

#### POST `/mahasiswa/attendance/check-in`
```json
// Request
{
    "jadwal_id": 5,
    "client_uuid": "550e8400-e29b-41d4-a716-446655440000",
    "permit_token": "64-character-secret",
    "latitude": -0.0263,
    "longitude": 109.3425,
    "gps_accuracy": 8.5,
    "face_distance": 0.7234,
    "liveness_passed": true,
    "liveness_challenge": "smile",
    "inference_time_ms": 145,        // R-06: disimpan ke attendance_logs
    "device_model": "Samsung Galaxy A54",
    "device_os": "Android 14",
    "app_version": "1.0.0",
    "mock_location_detected": false  // C-03/R-03: nama field WAJIB ini
}

// Response 201
{
    "success": true,
    "message": "Check-in berhasil",
    "data": {
        "attendance": { "id": 123, "status": "hadir", "checkin_time": "2026-05-27T07:05:00Z", "alpha_menit": 0 },
        "status": "hadir",
        "message": "Check-in berhasil"
    }
}

// Response 422 (mock location terdeteksi → ditolak & dicatat ke attendance_logs)
{ "success": false, "message": "Terdeteksi manipulasi lokasi (fake GPS)." }
```

#### POST `/mahasiswa/attendance/check-out`
```json
// Request
{
    "attendance_id": 123,
    "jadwal_id": 5,
    "client_uuid": "550e8400-e29b-41d4-a716-446655440001",
    "permit_token": "another-64-character-secret",
    "latitude": -0.0263,
    "longitude": 109.3425,
    "gps_accuracy": 8.5,
    "face_distance": 0.6891,
    "liveness_passed": true,
    "liveness_challenge": "blink",
    "inference_time_ms": 132,
    "device_model": "Samsung Galaxy A54",
    "device_os": "Android 14",
    "app_version": "1.0.0",
    "mock_location_detected": false
}

// Response 200
{
    "success": true,
    "message": "Check-out berhasil",
    "data": {
        "attendance": { "id": 123, "status": "hadir", "checkout_time": "2026-05-27T10:00:00Z" },
        "checkout_time": "2026-05-27T10:00:00Z",
        "durasi_efektif_menit": 175
    }
}
```

#### POST `/mahasiswa/attendance/sync-offline` (C-04, C-05, M-02)
```json
// Request — batch, maks 20 item; tiap item butuh client_uuid (idempotent)
{
    "attendances": [
        {
            "client_uuid": "550e8400-e29b-41d4-a716-446655440000",
            "jadwal_id": 5,
            "type": "check_in",
            "timestamp": "2026-05-27T07:05:00Z",
            "latitude": -0.0263,
            "longitude": 109.3425,
            "face_distance": 0.72,
            "liveness_passed": true,
            "liveness_challenge": "smile",
            "permit_token": "64-character-secret",
            "mock_location_detected": false
        }
    ]
}

// Response 200 — hasil dipetakan per client_uuid
{
    "success": true,
    "data": {
        "results": [
            { "client_uuid": "550e8400-...", "jadwal_id": 5, "status": "success", "attendance_id": 321 }
            // status lain mengikuti hasil item controller, misalnya "skipped" atau "failed"
        ]
    }
}
```

#### GET `/mahasiswa/jadwal/today` (H-03)
```json
// Response 200 — tiap jadwal kini menyertakan status absensi
{
    "success": true,
    "data": [
        {
            "id": 5,
            "mata_kuliah_id": 12,
            "mata_kuliah": { "nama": "Matematika Diskrit", "dosen": { "nama": "Yusril Eka, M.TI" } },
            "hari": "Selasa",
            "jam_mulai": "07:00",
            "jam_selesai": "10:00",
            "ruangan": "Lab Komputer 1",
            "geofence": { "latitude": -0.0263, "longitude": 109.3425, "radius": 50 },
            "attendance_id": null,
            "attendance_status": null,
            "checkin_time": null,
            "checkout_time": null
        }
    ]
}
```

> Catatan H-02: field radius geofence bernama **`radius`** (bukan `radius_meter`).

---

## 4. JADWAL ENDPOINTS (Mahasiswa)

| Method | Endpoint | Deskripsi | Role | Status |
|--------|----------|-----------|------|--------|
| GET | `/mahasiswa/jadwal` | List jadwal mahasiswa | Mahasiswa | ✅ |
| GET | `/mahasiswa/jadwal/today` | Jadwal + status absensi hari ini | Mahasiswa | ✅ |
| GET | `/mahasiswa/jadwal/active` | Jadwal yang sedang berlangsung | Mahasiswa | ✅ |

---

## 5. LEAVE REQUEST ENDPOINTS (Izin/Sakit)

| Method | Endpoint | Deskripsi | Role | Status |
|--------|----------|-----------|------|--------|
| GET | `/mahasiswa/leave-requests` | List izin saya | Mahasiswa | 🟡 (dulu `/leaves/my`) |
| POST | `/mahasiswa/leave-requests` | Submit izin/sakit | Mahasiswa | 🟡 (dulu `/leaves`) |
| GET | `/kaprodi/leave-requests` | List izin (approval) | Kaprodi | 🟡 (dulu `/leaves/pending`) |
| PUT | `/kaprodi/leave-requests/{id}/approve` | Approve izin | Kaprodi | ✅ |
| PUT | `/kaprodi/leave-requests/{id}/reject` | Reject izin | Kaprodi | ✅ |

---

## 6. ACADEMIC MANAGEMENT (Admin) — prefix `/admin`

Middleware role: `super_admin,admin_jurusan,admin_prodi`. Sebagian besar memakai `apiResource`.

### Tahun Ajaran & Semester
| Method | Endpoint | Status |
|--------|----------|--------|
| apiResource | `/admin/tahun-ajaran` | 🟡 (dulu `/academic/tahun-ajaran`) |
| apiResource | `/admin/semester` | 🟡 (dulu `/academic/semesters`) |

### Mata Kuliah
| Method | Endpoint | Status |
|--------|----------|--------|
| apiResource | `/admin/mata-kuliah` | 🟡 |
| POST | `/admin/mata-kuliah/{id}/enroll` | 🟡 (dulu `/assign-mahasiswa`) |
| DELETE | `/admin/mata-kuliah/{id}/remove-mahasiswa` | ✅ |

### Jadwal & Geofence
| Method | Endpoint | Status |
|--------|----------|--------|
| apiResource | `/admin/jadwal` | 🟡 |
| apiResource | `/admin/geofence` | 🟡 (dulu `/academic/geofences`) |

### Prodi & Settings
| Method | Endpoint | Status |
|--------|----------|--------|
| apiResource | `/admin/prodi` | ✅ |
| PUT | `/admin/prodi/{id}/settings` | 🟡 (dulu `/settings/prodi/{id}`) |
| GET/PUT | `/admin/settings` | 🟡 (dulu `/settings/system`) |
| GET | `/admin/dashboard` | 🟡 (dulu `/reports/dashboard/admin-prodi`) |

> Test mode: `PUT /settings/test-mode` (draf lama) → sekarang `/admin/test-mode/toggle` (lihat §12).

---

## 7. USER MANAGEMENT (Admin) — prefix `/admin`

| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| apiResource | `/admin/users` | CRUD user (semua role) | 🟡 (dulu terpisah `/users/mahasiswa`, `/users/dosen`) |
| POST | `/admin/users/import` | Import bulk Excel | 🟡 |
| GET | `/admin/users/export` | Export Excel | 🟡 |
| POST | `/admin/users/{id}/reset-password` | Reset password user | ✅ |
| PUT | `/admin/users/{id}/toggle-status` | Aktif/nonaktif | ✅ |
| GET | `/admin/audit-trail` | Audit trail | ✅ |

> Catatan: tidak ada lagi pemisahan path `/users/mahasiswa` vs `/users/dosen`; gunakan
> `apiResource('users')` dengan filter query (mis. `?role=mahasiswa&prodi_id=...`).

---

## 8. DOSEN ENDPOINTS — prefix `/dosen`

Middleware role: `dosen,super_admin`.

| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/dosen/mata-kuliah` | MK yang diampu | 🟡 (dulu `/dosen/my-classes`) |
| GET | `/dosen/mata-kuliah/{id}/mahasiswa` | List mahasiswa di MK | ✅ |
| GET | `/dosen/attendance` | List kehadiran | ✅ |
| GET | `/dosen/attendance/class-today` | Kehadiran kelas hari ini | 🟡 (dulu `/dosen/class/{jadwal_id}/today`) |
| GET | `/dosen/attendance/rekap/{mataKuliahId}` | Rekap per MK | 🟡 (dulu `/dosen/class/{mk_id}/recap`) |
| PUT | `/dosen/attendance/{id}/approve` | Approve pending | 🟡 |
| PUT | `/dosen/attendance/{id}/reject` | Reject pending | 🟡 |
| PUT | `/dosen/attendance/{id}/override` | Override manual | 🟡 (dulu `POST /dosen/attendance/override`) |
| GET | `/dosen/dashboard` | Dashboard dosen | 🟡 |

---

## 9. SP (SURAT PERINGATAN) ENDPOINTS

Tersebar di beberapa prefix sesuai peran (alur TTD: Admin generate → Kaprodi sign → Kajur sign).

### Admin — `/admin/sp-records`
| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/admin/sp-records` | List SP records | ✅ |
| GET | `/admin/sp-records/{id}` | Detail SP | ✅ |
| POST | `/admin/sp-records/generate` | Generate dokumen SP | 🟡 (dulu `/sp/generate`) |
| POST | `/admin/sp-records/{id}/send-to-kaprodi` | Kirim ke Kaprodi | ✅ |
| GET | `/admin/sp-records/{id}/download` | Download PDF | 🟡 (dulu `/sp/records/{id}/document`) |
| POST | `/admin/sp-records/{id}/cancel` | Batalkan SP | ✅ |

### Kaprodi — `/kaprodi/sp-records`
| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/kaprodi/sp-records` | List | ✅ |
| GET | `/kaprodi/sp-records/{id}` | Detail | ✅ |
| PUT | `/kaprodi/sp-records/{id}/sign` | TTD Kaprodi | 🟡 (dulu `/sp/records/{id}/sign-kaprodi`) |
| POST | `/kaprodi/sp-records/{id}/cancel` | Batalkan | ✅ |

### Kajur — `/kajur/sp-records`
| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/kajur/sp-records` | List | ✅ |
| GET | `/kajur/sp-records/{id}` | Detail | ✅ |
| PUT | `/kajur/sp-records/{id}/sign` | TTD Ketua Jurusan | 🟡 (dulu `/sp/records/{id}/sign-kajur`) |

### Mahasiswa — `/mahasiswa/sp-records`
| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/mahasiswa/sp-records` | SP saya | 🟡 (dulu `/sp/my`) |
| GET | `/mahasiswa/sp-records/{id}` | Detail SP saya | ✅ |

---

## 10. DASHBOARD & REPORTS (Admin) — prefix `/admin/reports`

| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/admin/reports/by-mahasiswa` | Rekap per mahasiswa | 🟡 (query param, bukan `/{id}`) |
| GET | `/admin/reports/by-mata-kuliah` | Rekap per MK | 🟡 |
| GET | `/admin/reports/by-kelas` | Rekap per kelas | ✅ |
| GET | `/admin/reports/by-prodi` | Rekap per prodi | 🟡 |
| GET | `/admin/reports/by-jurusan` | Rekap jurusan | ✅ |
| GET | `/admin/reports/export/pdf` | Export PDF (throttle `export`) | ✅ |
| GET | `/admin/reports/export/excel` | Export Excel (throttle `export`) | ✅ |

Dashboard per peran (bukan di `/reports/dashboard/*` lagi):
| Method | Endpoint | Status |
|--------|----------|--------|
| GET | `/admin/dashboard` | ✅ |
| GET | `/kaprodi/dashboard` | 🟡 |
| GET | `/kajur/dashboard` | 🟡 |
| GET | `/dosen/dashboard` | 🟡 |
| GET | `/mahasiswa/dashboard` | ✅ |
| GET | `/orang-tua/dashboard` | ✅ |

---

## 11. NOTIFICATION ENDPOINTS — prefix `/notifications`

| Method | Endpoint | Deskripsi | Role | Status |
|--------|----------|-----------|------|--------|
| GET | `/notifications` | List notifikasi saya (paginated) | All (auth) | ✅ |
| GET | `/notifications/unread-count` | Jumlah belum dibaca | All (auth) | ✅ |
| PUT | `/notifications/{id}/read` | Mark as read | All (auth) | ✅ |
| PUT | `/notifications/read-all` | Mark all as read | All (auth) | ✅ |

> Tipe notifikasi valid (enum, L-05): `sp_warning`, `sp_issued`, `approval_needed`,
> `approval_result`, `enrollment_result`, `reminder`, `system`, `attendance_reminder`,
> `leave_request_result`.

---

## 12. ANALISIS & EVALUASI + TEST MODE (Admin) — prefix `/admin`

### Analysis — `/admin/analysis`
| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/admin/analysis/geofence` | Evaluasi geofence | ✅ |
| GET | `/admin/analysis/face-verification` | Evaluasi face verify (FAR/FRR dihitung di sini) | ✅ |
| GET | `/admin/analysis/latency` | Evaluasi latensi (R-06) | ✅ |
| GET | `/admin/analysis/attendance-sp` | Evaluasi kehadiran & SP | ✅ |
| GET | `/admin/analysis/simultaneous-test` | Data uji simultan (R-07) | ✅ |
| GET | `/admin/analysis/conventional-comparison` | Perbandingan konvensional (R-08) | ✅ |
| POST | `/admin/analysis/conventional-data` | Input data konvensional manual | ✅ |
| ~~GET~~ | ~~`/analysis/far-frr`~~ | FAR/FRR terpisah | ❌ (dihitung dalam `face-verification` + `test-mode/summary`) |
| ~~GET~~ | ~~`/analysis/documentation`~~ | Dokumentasi rumus | ❌ (tidak diimplementasikan sebagai endpoint) |

**Parameter `prodi_id` dan scope aktor (current — R-04/M-24).** Seluruh endpoint
di atas menerima `?prodi_id`, dan artinya berbeda dari desain awal:

- `prodi_id` mempersempit **dataset**, bukan hanya memilih `face_threshold`.
  Atribusinya memakai prodi subjek (`users.prodi_id`), sama dengan sumber ambang
  runtime.
- `prodi_id` tidak dikenal menghasilkan `422`, bukan dataset kosong.
- Group route ini terbuka untuk `super_admin`, `admin_jurusan`, dan
  `admin_prodi`, sehingga scope aktor diterapkan: role tingkat prodi dipaksa ke
  prodinya sendiri, meminta prodi lain menghasilkan `403`, dan aktor tanpa
  `prodi_id` fail-closed. Hanya `super_admin` yang dapat melihat gabungan
  seluruh prodi.
- Success rate geofence dihitung dari `checkin_success` vs `checkin_failed`,
  bukan `geofence_valid` (R-01).

Kontrak executable ada di [CURRENT-API.md](CURRENT-API.md); semantik untuk
laporan penelitian ada di [PRD-07-analisis-evaluasi.md](PRD-07-analisis-evaluasi.md).

### Test Mode — `/admin/test-mode` (R-05)
| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/admin/test-mode/status` | Status test mode | ✅ |
| POST | `/admin/test-mode/toggle` | Toggle test mode | ✅ |
| GET | `/admin/test-mode/logs` | Log percobaan (genuine/impostor) | ✅ |
| PUT | `/admin/test-mode/logs/{id}/label` | Label log (genuine/impostor) | ✅ |
| GET | `/admin/test-mode/summary` | Ringkasan FAR/FRR dari label | ✅ |

---

## 13. ORANG TUA ENDPOINTS — prefix `/orang-tua`

Middleware role: `orang_tua`. (Tidak ada di draf lama; ditambahkan agar dokumen lengkap.)

| Method | Endpoint | Deskripsi | Status |
|--------|----------|-----------|--------|
| GET | `/orang-tua/children` | List anak | ✅ |
| GET | `/orang-tua/children/{id}/attendance` | Kehadiran anak | ✅ |
| GET | `/orang-tua/children/{id}/sp-records` | SP anak | ✅ |
| GET | `/orang-tua/dashboard` | Dashboard orang tua | ✅ |

---

## RINGKASAN ENDPOINT YANG DIHAPUS / TIDAK DIIMPLEMENTASIKAN (D-02)

| Endpoint draf lama | Keputusan | Pengganti |
|--------------------|-----------|-----------|
| `/attendance/summary` | ❌ tidak ada | `GET /mahasiswa/dashboard` (summary semester) |
| `/attendance/active-schedule` | ❌ tidak ada | `GET /mahasiswa/jadwal/active` |
| `/enrollment/pending` | ❌ tidak ada | `GET /kaprodi/enrollments` |
| `/analysis/far-frr` | ❌ tidak ada | dihitung di `analysis/face-verification` & `test-mode/summary` |
| `/analysis/documentation` | ❌ tidak ada | dokumentasi rumus ditaruh di laporan/PRD, bukan endpoint |
| `/auth/update-profile` | ❌ tidak ada | `PUT /profile` |
| `/auth/update-fcm-token` | ❌ tidak ada | `POST /fcm-token` |
