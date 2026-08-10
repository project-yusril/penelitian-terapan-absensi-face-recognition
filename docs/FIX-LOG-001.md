# FIX LOG #001 — Eksekusi perbaikan dari `ANALISIS-BUG-REPORT.md`

> **ARSIP HISTORIS.** Route, permit, offline result, environment, dan release
> procedure telah berkembang. Gunakan [README.md](README.md) untuk referensi current.

**Tanggal:** 16 Juni 2026
**Cakupan:** Perbaikan batch pertama (CRITICAL & HIGH) — backend + frontend.
**Rujukan:** Setiap item mengacu pada kode temuan di `docs/ANALISIS-BUG-REPORT.md`
(mis. `C-01`, `H-02`, `M-02`).

> Catatan: ini bukan dokumen requirement — ini adalah **catatan eksekusi**.
> Untuk konteks lengkap "kenapa bug ini critical" baca `ANALISIS-BUG-REPORT.md`
> bagian terkait kode temuan.

---

## 1. CRITICAL & HIGH BACKEND

### [R-01] Foto biometrik tidak boleh public
- **File:** `backend/config/filesystems.php`
  - Ditambahkan disk **`face`** (`storage/app/face`, visibility `private`).
- **File:** `backend/app/Http/Controllers/Api/Mahasiswa/EnrollmentController.php`
  - `store()` & `requestReEnrollment()`: foto sekarang `->store('enrollment', 'face')`
    dan `->store('re-enrollment', 'face')` (sebelumnya `'public'`).
  - `status()`: tidak lagi expose URL public. Ganti dengan
    `URL::temporarySignedRoute('mahasiswa.enrollment.photo', 10 menit)`.
  - Method baru `enrollmentPhoto()` — serve file via signed URL.
- **File:** `backend/routes/api.php`
  - Route baru `GET /api/mahasiswa/{user}/enrollment-photo`
    middleware `signed` (HMAC).
- **Deploy notes:**
  - Pastikan folder `storage/app/face` ada dan writable oleh PHP-FPM.
  - JANGAN `php artisan storage:link` untuk disk ini.

### [M-06] Embedding size validation
- **File:** `EnrollmentController.php`
  - `EMBEDDING_SIZE = 192` (sesuai MobileFaceNet pada PRD-01).
  - Validasi diganti dari `min:128|max:512` → `size:192` eksak.

### [H-04] Single source of truth threshold wajah
- **File:** `EnrollmentController@getMyEmbedding`
  - Response sekarang ikut menyertakan `face_threshold` (per-prodi)
    dan `liveness_required`.
- **File:** `frontend/lib/features/face_recognition/presentation/bloc/face_bloc.dart`
  - Baca `face_threshold` (fallback `threshold` untuk backward compat).
- Hasilnya: mobile dan backend pakai ambang yang sama → tidak ada lagi
  false-accept akibat ambang client lebih longgar.

### [C-05] Offline sync tidak boleh bypass anti-spoofing
### [M-02] Offline sync wajib idempotent
- **File:** `backend/app/Http/Controllers/Api/Mahasiswa/OfflineSyncController.php`
  - Validasi sekarang **wajib** sertakan `mock_location_detected`, `liveness_passed`,
    `face_distance`, `client_uuid` (uuid v4).
  - Sebelum membuat record, sekarang dilakukan:
    1. Cek idempotency `attendances.client_uuid`. Jika sudah ada → return
       status `duplicate` (sukses, tidak duplikat).
    2. Validasi mock GPS (kecuali prodi `allow_mock_location=true`).
    3. Validasi liveness pass.
    4. Validasi geofence (haversine vs radius prodi/geofence).
    5. Validasi `face_distance ≤ face_threshold`.
  - Semua kegagalan di-log via `AttendanceLog` (action: `mock_location_detected`,
    `liveness_failed`, `geofence_invalid`, `face_not_match`).
  - Logika status check-in (hadir / hadir_terlambat / pending) & alpha_menit
    DISAMAKAN dengan path online (`AttendanceController`).
  - `SpDetectionService` di-trigger sekali per user di akhir batch (efisien).
- **File:** `backend/database/migrations/2026_06_16_000001_add_client_uuid_to_attendances_table.php`
  - Tambah kolom `attendances.client_uuid` (uuid, nullable) +
    unique `(user_id, client_uuid)`.
- **File:** `backend/app/Models/Attendance.php`
  - `client_uuid` ditambahkan ke `$fillable`.
- **Migrasi yang HARUS dijalankan:**
  ```bash
  cd backend
  php artisan migrate
  ```

---

## 2. CRITICAL & HIGH FRONTEND

### [C-01] Verifikasi wajah pakai bytes kosong
- **File:** `frontend/lib/features/face_recognition/domain/services/face_recognition_service.dart`
  - Ditambah method `generateEmbeddingFromCameraImage(CameraImage, Face)` dan
    `verifyFaceFromCamera(CameraImage, Face, ref, threshold)`.
  - Implementasi YUV420 → RGB (BT.601) dan BGRA8888 → RGB.
  - `verifyFace(Uint8List, ...)` yang lama sekarang **throw** kalau bytes kosong
    (mencegah regresi).
- **File:** `frontend/lib/features/attendance/presentation/pages/attendance_page.dart`
  - Simpan `_lastCameraImage` dari stream.
  - Pakai `verifyFaceFromCamera(_lastCameraImage!, …)` (bukan `Uint8List(0)`).

### [C-02] `liveness_passed` ter-hardcode `true`
- **File:** `attendance_page.dart`
  - Variabel state baru `_livenessPassed` (default `false`).
  - Di-set `true` HANYA kalau `LivenessDetectionService.checkChallenge` return
    `true` (pola NEUTRAL → CHALLENGE konsisten, lihat C-04).
  - Saat verifikasi wajah gagal → reset ke `false` sebelum challenge baru.
  - Payload `data['liveness_passed'] = _livenessPassed`.

### [C-04] Liveness bisa di-bypass dengan foto statis
- **File:** `frontend/lib/features/face_recognition/domain/services/liveness_detection_service.dart`
  - Ditulis ulang. Sekarang stateful:
    - Wajib lihat **frame netral** dulu (kepala lurus, mata terbuka, mulut
      tertutup untuk challenge `smile`).
    - Setelah netral, butuh **3 frame berturut-turut** memenuhi syarat delta
      (mis. smile prob naik ≥ 0.6, eulerY ≥ 25°, dst).
    - `reset()` saat challenge baru dipilih.
  - Tujuan: spoofing dengan foto cetak/selfie statis tidak lagi lolos.

### [H-02] GPS tanpa ambang akurasi
- **File:** `attendance_page.dart`
  - Const `AttendancePage.maxGpsAccuracy = 50` (meter).
  - Saat `_currentPosition!.accuracy > 50`, halaman menolak lokasi dengan
    pesan "Akurasi GPS terlalu rendah".

### [L-03] Mock GPS dipaksa `false` saat offline submit
- **File:** `attendance_page.dart`
  - `_submitAttendance` sekarang panggil ulang `SafeDevice.isMockLocation`
    dan mengirim hasilnya (`mock_location_detected: mockNow`).
  - Backend `OfflineSyncController` (C-05) akan reject jika `true`.

### [L-04] Status row warna salah
- **File:** `attendance_page.dart`
  - `_buildStatusRow` sekarang menerima parameter `isError` →
    icon merah (`Icons.error` + `AppColors.danger`) saat Fake GPS terdeteksi.

### [M-02 — sisi client] Idempotency UUID
- **File:** `attendance_page.dart`
  - Field `_clientUuid = const Uuid().v4()` di-generate sekali per attempt.
  - Field `'client_uuid'` selalu disertakan baik di payload online maupun offline.
- **File:** `frontend/lib/core/network/connectivity_service.dart`
  - Sync ulang sekarang ditujukan ke endpoint khusus
    `ApiConstants.attendanceSyncOfflineEndpoint` (sebelumnya: dikirim
    satu-per-satu ke endpoint online check-in/check-out).
  - Batch ukuran 20 (sesuai validasi backend), hasil per-item dipetakan via
    `client_uuid` → mark completed/failed sesuai status backend.
  - Status `duplicate` dianggap **sukses** (sesuai semantik idempotency).

---

## 3. YANG MASIH PERLU DIKERJAKAN (BELUM DI FIX-LOG INI)

Status berikut ini SUDAH ada di `ANALISIS-BUG-REPORT.md` dan harus
dilanjutkan di FIX-LOG berikutnya.

### Backend
| Kode | Ringkasan | Status |
|------|-----------|--------|
| C-03 | Mismatch field `is_mock_location` vs `mock_location_detected` di path online | belum diperiksa di sesi ini (verifikasi di `AttendanceController`) |
| C-04 (backend) | Endpoint offline sync salah path / payload | sudah ditangani sebagian via `connectivity_service.dart` + `OfflineSyncController` C-05 |
| H-01 | Alpha "pulang awal" untuk mahasiswa `hadir` tidak ikut ke SP | perlu cek `AlphaAccumulationService` |
| H-03 | Race condition double check-in (perlu DB lock) | belum |
| L-05 | Enum `notifications.type` belum mencakup `enrollment_*` & `re_enrollment_*` | perlu migrasi |
| R-05/R-07 | Pipeline test-mode & analysis untuk FAR/FRR | perlu verifikasi controller |

### Frontend
| Kode | Ringkasan | Status |
|------|-----------|--------|
| C-02 (online) | Hasil halaman absensi tidak pernah di-POST oleh `home_page.dart` | perlu fix di home_page.dart (await result + call AttendanceBloc) |
| M-01 | Token Sanctum tidak disimpan/dilampirkan ke header `Authorization` | perlu cek `api_client.dart` |
| M-03 | `FaceDetector` di-init di dua tempat (memory leak) | sudah berkurang setelah `LivenessDetectionService` tidak instansiasi `FaceDetector` sendiri |

### Docs
| Kode | Ringkasan | Status |
|------|-----------|--------|
| D-01 | Tabel `attendances` di PRD-03 perlu menambahkan `client_uuid` | TODO |
| D-02 | API contract PRD-04 untuk `/attendance/sync-offline` perlu diupdate (field baru) | TODO |

---

## 4. CHECKLIST DEPLOY UNTUK FIX-LOG INI

```bash
# 1. Pull kode terbaru
git pull

# 2. Backend
cd backend
composer install
php artisan migrate                      # menambah kolom client_uuid
php artisan config:cache
mkdir -p storage/app/face                # disk biometrik privat
chmod 750 storage/app/face

# 3. Frontend
cd ../frontend
flutter pub get
flutter clean
flutter build apk --release              # atau flutter run untuk dev
```

### Smoke test yang wajib dilakukan
1. **Enrollment baru** → cek file masuk ke `storage/app/face/enrollment/...`
   dan `GET /enrollment/status` mengembalikan signed URL (path
   `/api/mahasiswa/{id}/enrollment-photo?signature=...`).
2. **Coba akses foto** dengan URL TANPA signature → harus `403 Invalid signature`.
3. **Check-in online** → response 201, `attendances.client_uuid` terisi.
4. **Check-in offline** (matikan WiFi) → masuk Hive queue;
   nyalakan WiFi → `connectivity_service` sync ke `/attendance/sync-offline`,
   hasilnya berupa daftar `success`/`duplicate`/`failed` per `client_uuid`.
5. **Spoof test** (mock location aktif via developer settings) → tolakan
   muncul di UI (Fake GPS) DAN backend log `mock_location_detected`.
6. **Liveness spoof** (taruh foto cetak di depan kamera) → challenge tidak
   pernah pass karena tidak ada perubahan delta antara frame netral & challenge.

---

## 5. CATATAN AKHIR

- Semua perubahan PHP & Dart sudah diberi komentar dengan kode temuan
  (mis. `// C-01:`, `// M-02:`) supaya mudah ditelusuri saat code review.
- Backward compatibility: method lama `FaceRecognitionService.verifyFace`
  TETAP tersedia (hanya men-throw jika bytes kosong) supaya kalau ada
  pemanggil lain di codebase masih ke-detect saat runtime.
- File `OfflineSyncController` lama di-overwrite penuh; semantik response
  tetap kompatibel (`success`, `failed`, `results`) dengan tambahan field
  `duplicate` dan `expired`. Frontend `connectivity_service.dart` sudah
  diadaptasi untuk semantik baru ini.
