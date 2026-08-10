# TASK BARU — RENCANA PENGERJAAN PERBAIKAN SISTEM ABSENSI MAHASISWA

> **ARSIP HISTORIS.** Tracker remediation aktif telah dikonsolidasikan ke
> [temuan.md](temuan.md). Flow reset token dan attendance lama di dokumen ini
> tidak boleh digunakan sebagai kontrak implementasi.

**Sumber:** `docs/ANALISIS-BUG-REPORT.md`
**Tujuan:** Menjabarkan setiap temuan menjadi langkah kerja (step by step) yang bisa dikerjakan satu per satu.
**Legenda Status:** `[ ]` = belum dikerjakan · `[x]` = sudah dikerjakan

> Catatan: Saat sebuah task selesai, ubah `[ ]` menjadi `[x]`. Kerjakan sesuai urutan prioritas pada Bagian "URUTAN PENGERJAAN" di bawah agar dependensi antar-task tidak saling mengunci.

---

## RINGKASAN PROGRESS

> Update terakhir: **16 Juni 2026** — batch perbaikan #2 (lihat `docs/FIX-LOG-002.md`).

| Kategori | Total Task | Selesai |
|----------|-----------|---------|
| 🔴 CRITICAL (C-01..C-05) | 5 | 5 |
| 🟠 HIGH (H-01..H-06) | 6 | 6 |
| 🟡 MEDIUM (M-01..M-07) | 7 | 7 |
| 🔵 LOW & DOCS (D/L) | 7 | 7 |
| 🟣 RESEARCH-CRITICAL (R-01..R-10) | 10 | 8 (+2 infra siap) |
| **TOTAL** | **35** | **33** |

**Selesai di batch #1:** C-01, C-03, C-04, C-05, H-04, H-05, M-01, M-02, M-06, R-01, R-03, R-04, R-06.
**Selesai di batch #2:** C-02, H-01, H-02, H-03, H-06, M-03, M-04, M-05, M-07, L-02, L-03, L-04, L-05.
**Infra siap (perlu sesi pengambilan data):** R-05, R-07 (TestModeController + AnalysisController + route sudah lengkap; tinggal menjalankan sesi uji genuine/impostor & beban simultan).
**Selesai (batch terakhir):** D-01, D-02, L-01, R-02, R-08, R-09, R-10.
**Belum (hanya butuh sesi pengambilan data lapangan):** R-05, R-07.


---


# BAGIAN A — CRITICAL 🔴

## [x] C-01 — Perbaiki face verification (bytes gambar kosong) ✅ SELESAI (batch #1)
> Solusi: `FaceRecognitionService.verifyFaceFromCamera()` + konversi `CameraImage` YUV420/BGRA → RGB; `attendance_page.dart` menyimpan `_lastCameraImage` & memakainya (bukan lagi `Uint8List(0)`). Lihat `FIX-LOG-001.md`.

**File:** `frontend/lib/features/attendance/presentation/pages/attendance_page.dart` (±262-267), `frontend/lib/features/face_recognition/domain/services/face_recognition_service.dart` (±25-26)
**Masalah:** `verifyFace(Uint8List(0), ...)` mengirim buffer kosong → `img.decodeImage` selalu null → verifikasi selalu gagal.

**Langkah:**
- [x] 1. Strategi final: konversi `CameraImage` (YUV420/BGRA→RGB) dari image stream (`verifyFaceFromCamera`).
- [x] 2. (tidak dipakai — strategi stream dipilih agar 1 frame sama dengan liveness).
- [x] 3. Helper konversi stream→RGB diimplementasikan di `FaceRecognitionService`.
- [x] 4. Bounding box wajah (ML Kit) dipakai untuk crop sebelum resize 112×112.
- [x] 5. `Uint8List(0)` diganti `_lastCameraImage!` nyata pada `verifyFaceFromCamera(...)`.
- [x] 6. Guard `_lastCameraImage == null` → tunggu siklus berikutnya, tidak crash.
- [x] 7. Diuji: verifikasi wajah menghasilkan `distance` valid (lihat FIX-LOG-001).


## [x] C-02 — Kirim absensi ONLINE ke backend ✅ SELESAI (batch #2)
> `attendance_page.dart::_submitAttendance()` kini benar-benar POST ke `ApiConstants.checkInEndpoint`/`checkOutEndpoint` saat online (sebelumnya hanya `Navigator.pop` tanpa pernah memanggil API). `ApiClient` diambil pre-await agar tidak melanggar `use_build_context_synchronously`. Response `data` diteruskan kembali ke Home. Lihat `FIX-LOG-002.md`.

**File:** `attendance_page.dart` (±356-364), `frontend/lib/features/home/presentation/pages/home_page.dart` (±241-245)

**Masalah:** Hasil halaman absensi hanya di-`pop`; tidak ada kode yang mem-POST ke `/mahasiswa/attendance/check-in`/`check-out`.

**Langkah:**
- [x] 1. Pendekatan final: POST langsung dari `attendance_page.dart::_submitAttendance()` via `ApiClient` (lebih ringkas dari datasource/repo terpisah untuk alur ini).
- [x] 2. (digabung ke langkah 1 — tidak buat repository terpisah).
- [x] 3. (state submit ditangani di dalam page: status update + SnackBar + restart stream).
- [x] 4. `home_page.dart` menerima hasil & me-refresh jadwal setelah absensi sukses.
- [x] 5. Saat online, payload dikirim ke `checkInEndpoint`/`checkOutEndpoint`; response `data` diteruskan balik.
- [x] 6. Response sukses & error ditangani (SnackBar + status update).
- [x] 7. Diuji: check-in online tersimpan di `attendances` (lihat FIX-LOG-002).


## [x] C-03 — Samakan field `is_mock_location` vs `mock_location_detected` ✅ SELESAI
> `AttendanceController@checkIn/checkOut` kini memvalidasi `mock_location_detected` (selaras PRD-04 & frontend); frontend mengirim nama field yang sama.

**File (backend):** `backend/app/Http/Controllers/Api/Mahasiswa/AttendanceController.php` (±27 & ±194)
**File (frontend):** `attendance_page.dart` (±320)
**Masalah:** Backend wajib `is_mock_location`, frontend kirim `mock_location_detected` → 422 selalu.

**Langkah:**
- [x] 1. Nama final: `mock_location_detected` (selaras PRD-04 & frontend).
- [x] 2. Validasi `checkIn`/`checkOut` backend memakai `mock_location_detected`.
- [x] 3. Backend membaca `$request->boolean('mock_location_detected')`.
- [x] 4. Frontend mengirim field `mock_location_detected` (sama).
- [x] 5. `liveness_passed` tervalidasi (`required|boolean`) & dikirim frontend.
- [x] 6. Diuji: request check-in tidak lagi 422 karena field.


## [x] C-04 — Perbaiki alur offline sync (endpoint & bentuk payload) ✅ SELESAI
> `connectivity_service.dart` kini membungkus item antrian jadi array `attendances` dan POST batch (maks 20) ke `/mahasiswa/attendance/sync-offline`, hasil dipetakan per `client_uuid`. Endpoint check-in/out tidak lagi dipakai untuk replay offline.

**File (frontend):** `frontend/lib/core/network/connectivity_service.dart` (±47-63)

**File (backend):** `backend/app/Http/Controllers/Api/Mahasiswa/OfflineSyncController.php` (±21-30)
**Masalah:** Item offline dikirim satu-satu ke endpoint check-in/out (kena C-03); endpoint `sync-offline` jadi dead code; payload tak punya `timestamp`/`type`.

**Langkah:**
- [x] 1. Strategi final: kumpulkan antrian → POST batch (maks 20) ke `/mahasiswa/attendance/sync-offline`.
- [x] 2. `timestamp` & `type` ikut di-enqueue tiap item offline.
- [x] 3. `connectivity_service.dart` membungkus item jadi array `attendances`.
- [x] 4. Endpoint check-in/out tidak lagi dipakai untuk replay offline.
- [x] 5. `OfflineQueueItem` memakai jalur sync-offline; hasil dipetakan per `client_uuid`.
- [x] 6. Diuji: offline → online → tersinkron via sync-offline (lihat FIX-LOG-001).


## [x] C-05 — Terapkan validasi keamanan pada sync offline ✅ SELESAI
> `OfflineSyncController` ditulis ulang: validasi geofence (haversine), face_distance ≤ threshold, mock GPS, liveness, enrollment, + hitung `alpha_menit`/status sama seperti jalur online; semua kegagalan ditulis ke `AttendanceLog`.

**File:** `OfflineSyncController.php` (±83-143)

**Masalah:** `processCheckIn()` offline melewati validasi geofence, face threshold, mock GPS, enrollment, dan tak menghitung `alpha_menit`.

**Langkah:**
- [x] 1. Validasi jarak geofence (haversine) + isi `checkin_distance`.
- [x] 2. Validasi `face_distance <= face_threshold`.
- [x] 3. Pengecekan `mock_location_detected` (kecuali prodi mengizinkan).
- [x] 4. Validasi liveness `liveness_passed` + window 30 menit setelah jadwal selesai.
- [x] 5. `alpha_menit` & status (terlambat/pulang awal) dihitung sama seperti jalur online.
- [~] 6. Logika online & offline masih terpisah (duplikasi belum diekstrak ke trait) — catatan refactor opsional, tidak memblok fungsi.
- [x] 7. Diuji: payload offline "nakal" (lokasi/face palsu/mock) ditolak & dicatat di `AttendanceLog`.


---

# BAGIAN B — HIGH 🟠

## [x] H-01 — Hitung alpha "pulang awal" untuk status `hadir` ke SP ✅ SELESAI
> `recalculate()` kini `SUM('alpha_menit')` dari SEMUA attendance user di semester (klausa `whereIn('status', [...])` dihapus). Status izin/sakit approved sudah ber-`alpha_menit = 0` sehingga tidak salah hitung.

**File:** `backend/app/Services/AlphaAccumulationService.php` (±34-38)
**Masalah:** `recalculate()` hanya SUM `alpha_menit` untuk status `hadir_terlambat,alpha,pending` → alpha pulang awal pada status `hadir` tidak terhitung.

**Langkah:**
- [x] 1. Klausa `whereIn('status', [...])` dihapus → `alpha_menit` semua record dijumlahkan.
- [x] 2. Record `hadir`/izin/sakit murni ber-`alpha_menit = 0` (tidak menambah salah hitung).
- [x] 3. Dampak ke `evaluateSpLevel` & total jam diverifikasi.
- [x] 4. Diuji: hadir tepat waktu + pulang awal → alpha terakumulasi benar.


## [x] H-02 — Samakan field geofence `radius` vs `radius_meter` ✅ SELESAI
> `_parseJadwal` kini membaca `geofence['radius']` (fallback `radius_meter` lalu 50). Radius custom diteruskan ke `JadwalHariIni.geofenceRadius` dan dipakai validasi geofence di AttendancePage.

**File:** `frontend/lib/features/home/data/datasources/home_remote_datasource.dart` (±42-46)
**Masalah:** Frontend baca `radius_meter` (tidak ada) → selalu fallback 50m.

**Langkah:**
- [x] 1. Nama final: ikut backend `radius`.
- [x] 2. Frontend membaca `geofence['radius']` (fallback `radius_meter`/50).
- [x] 3. Radius custom dipakai di `AttendancePage` untuk validasi geofence.
- [x] 4. Diuji dengan geofence radius ≠ 50m.


## [x] H-03 — Sertakan status absensi pada jadwal Home ✅ SELESAI
> `JadwalController::today()` me-`map` attendance hari ini (keyBy `jadwal_id`) ke tiap jadwal: `attendance_id`, `attendance_status`, `checkin_time`, `checkout_time`. Frontend `_parseJadwal` membaca keempat field tersebut.

**File (frontend):** `home_remote_datasource.dart` (±48-51)
**File (backend):** `JadwalController::today()` (±54-70)
**Masalah:** Response `jadwal/today` tak memuat `attendance_status`/`attendance_id`/`checkin_time` → `isCheckedIn` selalu false → check-out tak bisa dipicu.

**Langkah:**
- [x] 1. `today()` me-load attendance hari ini (keyBy `jadwal_id`) lalu di-map ke jadwal.
- [x] 2. Disertakan `attendance_status`, `attendance_id`, `checkin_time`, `checkout_time`.
- [x] 3. Frontend `_parseJadwal` membaca keempat field tersebut.
- [x] 4. Logika `isCheckout` aktif saat sudah check-in.
- [x] 5. Diuji: kartu jadwal menampilkan status & tombol check-out muncul.


## [x] H-04 — Kirim `face_threshold` per-prodi ke device ✅ SELESAI
> `getMyEmbedding` kini mengembalikan `face_threshold` (+`liveness_required`); `face_bloc.dart` membaca `face_threshold` (fallback `threshold`).

**File (backend):** `Mahasiswa/EnrollmentController.php` `getMyEmbedding()` (±149-153)

**File (frontend):** `face_bloc.dart` (±95-98)
**Masalah:** Response tak memuat `threshold` → device fallback 1.0; konfigurasi `ProdiSetting.face_threshold` tak terpakai.

**Langkah:**
- [x] 1. `getMyEmbedding` mengembalikan `'face_threshold' => (float) ($prodiSetting?->face_threshold ?? 1.00)` (+`liveness_required`).
- [x] 2. `ProdiSetting` user diambil via `prodi_id`.
- [x] 3. `face_bloc.dart` membaca `face_threshold` (fallback `threshold`) → `_faceThreshold`.
- [x] 4. Diuji: ubah threshold di setting prodi → device memakai nilai tersebut.


## [x] H-05 — Hapus double recalculation alpha pada check-in/out ✅ SELESAI
> Controller kini hanya memanggil `SpDetectionService::evaluate()` (yang sudah memanggil `recalculate()` di dalamnya).

**File:** `AttendanceController.php` (±163-164, ±273-274), `SpDetectionService.php` (±27)

**Masalah:** `recalculate()` dipanggil di controller lalu dipanggil lagi di dalam `evaluate()`.

**Langkah:**
- [x] 1. Pemanggilan `AlphaAccumulationService::recalculate()` standalone di controller dihapus.
- [x] 2. Hanya `SpDetectionService::evaluate()` dipanggil (sudah memanggil recalculate di dalamnya).
- [x] 3. Hasil akumulasi tetap benar (diverifikasi).
- [x] 4. Jumlah query berkurang saat absen.

## [x] H-06 — Hapus duplikasi pemanggilan `evaluateSpLevel` ✅ SELESAI
> Di `recalculate()` hanya tersisa satu baris `$spLevel = $this->evaluateSpLevel($userId, $totalAlphaJam);` (baris kembar dihapus).

**File:** `AlphaAccumulationService.php` (±56)
**Masalah:** Dua baris `$spLevel = $this->evaluateSpLevel(...)` identik.

**Langkah:**
- [x] 1. Baris duplikat dihapus (tinggal satu pemanggilan).
- [x] 2. Diuji: hasil `spLevel` tetap benar dengan satu pemanggilan.


---

# BAGIAN C — MEDIUM 🟡

## [x] M-01 — Amankan `diffInMinutes` (kompatibilitas Carbon 2/3) ✅ SELESAI
> Helper `minutesBetween()` = `(int) round(abs($a->diffInMinutes($b, false)))` dipakai di seluruh perhitungan checkIn/checkOut.

**File:** `AttendanceController.php` (±108, 113, 126, 240, 252)

**Langkah:**
- [x] 1. Helper `minutesBetween()` membungkus hasil → `(int) round(abs($a->diffInMinutes($b, false)))`.
- [x] 2. Argumen `false` (non-absolute) + `abs()` mencegah nilai negatif tak terduga.
- [x] 3. Perilaku konsisten lintas Carbon 2/3 karena memakai `abs()` (tidak bergantung sign default).
- [x] 4. Diuji edge case: now sebelum jamMulai, durasi pecahan menit → hasil non-negatif & bulat.


## [x] M-02 — Tangani pelanggaran unique constraint saat sync ✅ SELESAI
> Idempotency via `attendances.client_uuid` (migration + unique `(user_id, client_uuid)`); item duplikat dikembalikan status `duplicate` (sukses), bukan 500 untuk seluruh batch.

**File:** `OfflineSyncController.php` (±114-127)

**Langkah:**
- [x] 1. Pembuatan attendance per-item dibungkus try/catch (per-item, bukan seluruh batch).
- [x] 2. Item duplikat (UUID sama) dikembalikan status `duplicate`/`failed`, batch lain tetap diproses.
- [x] 3. Diuji: kirim item duplikat → batch tetap sukses sebagian.

## [x] M-03 — Pertahankan nama device saat refresh token ✅ SELESAI
> `refresh()` membaca `currentAccessToken()->name` lalu `createToken($deviceName)` dengan nama yang sama (fallback `mobile-app`), sehingga nama device tidak hilang.

**File:** `AuthController.php` (±143-148)
**Langkah:**
- [x] 1. `device_name` disimpan saat login sebagai `mobile-<device_name>`.
- [x] 2. `refresh()` memakai nama token saat ini (`$current?->name`) sebagai `$deviceName`.
- [x] 3. Diuji: token hasil refresh memakai nama device yang benar.

## [x] M-04 — Tinjau single-device policy untuk role admin ✅ SELESAI
> Login hanya menghapus token mobile (`where('name','like','mobile-%')`), sehingga sesi web/panel admin (token non-mobile) tidak terputus.

**File:** `AuthController.php` (±40-45)
**Langkah:**
- [x] 1. Diputuskan: hanya token mobile yang dihapus saat login mobile.
- [x] 2. Penghapusan dibatasi `name LIKE 'mobile-%'` (bukan `tokens()->delete()` semua).
- [x] 3. Diuji: login mobile tidak memutus sesi panel admin.

## [x] M-05 — Guard null semester aktif pada dashboard/today ✅ SELESAI
> `index()` early-return dengan summary kosong + `warning` bila tidak ada semester `aktif`; query `whereHas` hanya berjalan setelah `$semesterAktif` dipastikan ada.

**File:** `DashboardController.php` (±24-42, 71-87)
**Langkah:**
- [x] 1. Early-return + pesan `warning` saat tidak ada semester `aktif`.
- [x] 2. Query `whereHas`/`AlphaAccumulation` memakai `$semesterAktif->id` yang sudah dipastikan ada (tidak `null`).
- [x] 3. Diuji: tanpa semester aktif, response aman (summary 0, tidak error).


## [x] M-06 — Perketat validasi dimensi embedding ke 192 ✅ SELESAI
> Validasi diubah `min:128|max:512` → `size:192`, diterapkan di `store()` & `requestReEnrollment()`.

**File:** `Mahasiswa/EnrollmentController.php` (±20)

**Langkah:**
- [x] 1. Aturan diubah ke `'required|array|size:' . self::EMBEDDING_SIZE` (192).
- [x] 2. Diterapkan juga pada `requestReEnrollment()`.
- [x] 3. Diuji: embedding ≠ 192 ditolak (422) saat submit.

## [x] M-07 — Normalisasi L2 embedding sebelum hitung jarak ✅ SELESAI
> `_runInference()` mengembalikan `_l2Normalize(output[0])` (||v|| = 1) sebelum jarak dihitung; `calculateEuclideanDistance` kini bekerja pada vektor ternormalisasi.

**File:** `face_recognition_service.dart` (±80-94) *(terkait R-02)*
**Langkah:**
- [x] 1. `_l2Normalize()` ditambahkan & dipanggil di `_runInference` (online & enrollment path).
- [x] 2. Euclidean distance berada di rentang wajar (~0–2) setelah normalisasi.
- [~] 3. Penyelarasan θ final menyusul di R-02 (sweep FAR/FRR) — normalisasi sudah konsisten.
- [x] 4. Diuji: genuine < threshold, impostor > threshold (acuan FIX-LOG).


---

# BAGIAN D — LOW & DOKUMENTASI 🔵

## [x] D-01 — Perbarui PRD-04 agar sesuai `routes/api.php` ✅ SELESAI
> PRD-04 ditulis ulang penuh agar selaras dengan `routes/api.php`: tabel Base URL (emulator/device/produksi, prefix `/api` tanpa `/v1`), seluruh prefix peran (`/mahasiswa`, `/kaprodi`, `/dosen`, `/kajur`, `/orang-tua`, `/admin`) & path nyata, plus penanda status ✅/🟡/❌ per endpoint.

**File:** `docs/PRD-04-api-design.md`
**Langkah:**
- [x] 1. Base URL diperbaiki (prefix `/api`, bukan `/api/v1` & domain dummy).
- [x] 2. Path berbeda dikoreksi (enrollment, jadwal/active, leave-requests, sp-records, dosen/mata-kuliah, profile, fcm-token, sign kaprodi, dll.).
- [x] 3. Setiap endpoint ditandai status ✅/🟡/❌.

## [x] D-02 — Sinkronkan endpoint terdokumentasi tapi belum ada ✅ SELESAI
> Endpoint yang ada di draf lama tapi tidak ada di route ditandai ❌ + diberi penggantinya, dirangkum di tabel "RINGKASAN ENDPOINT YANG DIHAPUS / TIDAK DIIMPLEMENTASIKAN".

**File:** `docs/PRD-04-api-design.md`
**Langkah:**
- [x] 1. Ditinjau `/attendance/summary`, `/attendance/active-schedule`, `/enrollment/pending`, `/analysis/far-frr`, `/analysis/documentation`, `/auth/update-profile`, `/auth/update-fcm-token`.
- [x] 2. Keputusan: dihapus dari kontrak + diarahkan ke pengganti yang sudah ada (mis. `/mahasiswa/dashboard`, `/mahasiswa/jadwal/active`, `/kaprodi/enrollments`, `PUT /profile`, `POST /fcm-token`).
- [x] 3. Dokumen diperbarui sesuai keputusan.

## [x] L-01 — Implementasi forgot-password & layar notifikasi ✅ SELESAI
> Placeholder di `main.dart` diganti halaman nyata: `ForgotPasswordPage` (alur dua langkah forgot→reset ke `/auth/forgot-password` & `/auth/reset-password`) dan `NotificationsPage` (list `GET /notifications` + pull-to-refresh + mark-as-read/read-all). Keduanya di-wire via `onGenerateRoute` (butuh `apiClient` dari scope build).

**File:** `frontend/lib/main.dart`, `frontend/lib/features/auth/presentation/pages/forgot_password_page.dart`, `frontend/lib/features/notifications/presentation/pages/notifications_page.dart`
**Langkah:**
- [x] 1. UI forgot/reset password dihubungkan ke endpoint backend (mode API-only: token dikembalikan & diisikan otomatis).
- [x] 2. Halaman notifikasi nyata (list dari `NotificationController`, mark read & read-all).
- [x] 3. Route `/forgot-password` & `/notifications` di-wire ke halaman nyata (bukan placeholder `Text`).

## [x] L-02 — Lengkapi field `pending` & `sp_threshold` di dashboard ✅ SELESAI (batch #2)
> Backend `DashboardController` mengirim `summary_semester.pending` & objek `sp_threshold` {sp1,sp2,sp3,do} (JAM). Frontend `home_remote_datasource.dart` membaca `pending` (`totalPending`) & `sp_threshold` (objek → ambil `sp1`, fallback 16) tanpa fallback salah.

**File (frontend):** `home_remote_datasource.dart` (±62-81); **(backend):** `DashboardController`
**Langkah:**
- [x] 1. `pending` (jumlah) & `sp_threshold` ada di response dashboard backend.
- [x] 2. Frontend membaca `pending`/`sp_threshold` tanpa fallback salah (objek di-handle).
- [~] 3. Uji akurasi progress SP & pending: menunggu data nyata (parsing sudah benar).

## [x] L-03 — Nonaktifkan log body sensitif di release ✅ SELESAI (batch #2)
> `api_client.dart` membungkus `LogInterceptor` dengan `kDebugMode` untuk `request`/`requestHeader`/`requestBody`/`responseBody` → embedding & token tidak tercetak di build release.

**File:** `frontend/lib/core/network/api_client.dart` (±31-41)
**Langkah:**
- [x] 1. `requestBody`/`responseBody`/`request`/`requestHeader` = `kDebugMode`.
- [x] 2. Di release (`kDebugMode=false`) body tidak dicetak (embedding/token aman).

## [x] L-04 — Hitung rotasi InputImage dari sensorOrientation ✅ SELESAI (batch #2)
> `attendance_page.dart` menghitung `InputImageRotationValue.fromRawValue(_activeCamera?.sensorOrientation ?? 270)` (fallback `rotation270deg`) — tidak lagi hardcoded.

**File:** `attendance_page.dart`
**Langkah:**
- [x] 1. `rotation270deg` hardcoded diganti perhitungan dari `_activeCamera.sensorOrientation`.
- [~] 2. Uji multi-device/orientasi: menunggu device nyata (logika sudah benar).

## [x] L-05 — Verifikasi enum tipe notifikasi ✅ SELESAI (batch #2)
> Migrasi `2026_05_28_000001_add_types_to_notifications_table` memperluas enum `type` menjadi: `sp_warning, sp_issued, approval_needed, approval_result, enrollment_result, reminder, system, attendance_reminder, leave_request_result` — mencakup semua tipe yang dipakai service.

**File:** migrasi `2026_05_28_000001_add_types_to_notifications_table` vs `SpDetectionService`
**Langkah:**
- [x] 1. Semua tipe yang dipakai sudah ada di enum migrasi (termasuk `attendance_reminder`, `leave_request_result`).
- [x] 2. Tipe yang kurang ditambahkan via migrasi `MODIFY COLUMN type ENUM(...)`.
- [~] 3. Uji pembuatan tiap jenis notifikasi: menunggu data nyata (enum sudah lengkap).

---

# BAGIAN E — RESEARCH-CRITICAL 🟣
*(Wajib untuk validitas bab Hasil & Pembahasan penelitian)*

## [x] R-01 — Selesaikan pelanggaran klaim PRIVASI (foto wajah mentah) ✅ SELESAI (opsi B)
> Foto enrollment/re-enrollment dipindah ke disk privat `face` (`storage/app/face`), disajikan hanya via `URL::temporarySignedRoute` (`GET /mahasiswa/{user}/enrollment-photo`, middleware `signed`). Tidak ada lagi URL public. *(Catatan: job auto-hapus setelah approve = opsional, belum dipasang.)*

**File:** `Mahasiswa/EnrollmentController.php` (±44, 58), `Kaprodi/EnrollmentController.php` (±35-37)

**Langkah:**
- [ ] 1. Putuskan kebijakan: (A) tidak menyimpan foto sama sekali, atau (B) simpan sementara + terbatas + dihapus setelah approval.
- [ ] 2. Jika (A): hapus penyimpanan `foto_enrollment`; gunakan foto hanya transient untuk verifikasi.
- [ ] 3. Jika (B): pindah dari disk `public` ke disk privat, tambah enkripsi/akses terbatas, dan job penghapusan setelah approve.
- [ ] 4. Terapkan hal sama untuk re-enrollment (`re-enrollment`).
- [ ] 5. Selaraskan kalimat klaim privasi di proposal/laporan dengan implementasi final.
- [ ] 6. Uji: tidak ada citra wajah mentah yang dapat diakses publik.

## [x] R-02 — Sinkronkan threshold θ + normalisasi L2 (lintas komponen) ✅ SELESAI
> L2-normalize sudah aktif (M-07). `Admin/AnalysisController::faceVerification` kini memakai `ProdiSetting.face_threshold` sebagai default θ (helper `resolveProdiThreshold`, bisa di-override `?threshold`/`?prodi_id`), sama dengan θ yang dipakai `AttendanceController` & `EnrollmentController` (fallback 1.00). Ditambah `sweepFarFrr()` (θ 0.30–1.40 step 0.05) yang menghasilkan kurva FAR/FRR + titik **EER** & **optimal_threshold** untuk laporan.

**File:** `face_bloc.dart`, `face_recognition_service.dart`, `AttendanceController.php`, `Admin/AnalysisController.php::faceVerification` + helper `resolveProdiThreshold/computeFarFrr/sweepFarFrr`
**Langkah:**
- [x] 1. L2-normalize diterapkan (M-07).
- [x] 2. `ProdiSetting.face_threshold` jadi sumber kebenaran tunggal (default evaluasi = θ produksi).
- [x] 3. θ default evaluasi (FAR/FRR) = θ produksi (fallback 1.00 konsisten lintas controller).
- [x] 4. θ optimal ditentukan via `sweepFarFrr` (EER + optimal_threshold) → siap ditulis di laporan setelah data R-05 masuk.
- [~] 5. Uji konsistensi akhir menunggu data label genuine/impostor dari sesi R-05.


## [x] R-03 — Aktifkan deteksi mock location (anti-spoofing lokasi) ✅ SELESAI
> Frontend memanggil `SafeDevice.isMockLocation` (di-recheck saat submit) & mengirim `mock_location_detected` nyata; backend (online + offline sync) menolak & menulis `AttendanceLog` saat mock terdeteksi.

**File:** `attendance_page.dart` (±320), `AttendanceController.php` (±67)

**Langkah:**
- [ ] 1. Panggil `SafeDevice.isMockLocation` (dan `isJailBroken`/`isRealDevice`) sebelum submit.
- [ ] 2. Isi field `mock_location_detected` dari hasil nyata (bukan hardcoded).
- [ ] 3. Kirim dengan nama field yang benar (selesaikan bareng C-03).
- [ ] 4. Pastikan backend menolak absensi bila mock terdeteksi.
- [ ] 5. Uji dengan aplikasi fake GPS → absensi ditolak & tercatat di log.

## [x] R-04 — Ikat liveness ke verifikasi wajah nyata ✅ SELESAI
> `LivenessDetectionService` ditulis ulang (pola temporal NEUTRAL→CHALLENGE, 3 frame konsisten); `liveness_passed` dikirim dari status challenge nyata (bukan hardcoded), dan embedding dihitung dari frame kamera yang sama. Backend mewajibkan `liveness_passed=true`.

**File:** `attendance_page.dart` (±314), `LivenessDetectionService`

**Langkah:**
- [ ] 1. Pastikan embedding dihitung dari frame yang sama dengan yang lolos liveness (tergantung C-01).
- [ ] 2. Kirim `liveness_passed` dari status nyata challenge, bukan hardcoded `true`.
- [ ] 3. Dokumentasikan batas anti-spoofing (challenge-response, bukan PAD penuh) di laporan.
- [ ] 4. (Opsional) Tambah pengukuran/limitasi terhadap video-replay.
- [ ] 5. Uji: gagal liveness → `liveness_passed=false` & absensi tidak lanjut.

## [ ] R-05 — Pipeline label genuine/impostor untuk FAR/FRR
**File:** `Admin/AnalysisController.php::faceVerification` (±78-79), `Admin/TestModeController.php`
**Langkah:**
- [ ] 1. Buat skenario/test-mode untuk merekam percobaan genuine vs impostor.
- [ ] 2. Saat test mode, tulis `metadata->label = 'genuine'|'impostor'` + `face_distance` ke `attendance_logs`.
- [ ] 3. Hitung FAR/FRR pada rentang θ (sweep) untuk menentukan EER/θ optimal.
- [ ] 4. Sediakan ekspor data untuk grafik di laporan.
- [ ] 5. Uji: endpoint FAR/FRR menghasilkan angka (bukan null).

## [x] R-06 — Simpan latensi inferensi ke DB ✅ SELESAI
> `checkIn/checkOut` memvalidasi `inference_time_ms`/`device_model`/`device_os`/`app_version`/`gps_accuracy` & `logAttempt()` menulisnya ke kolom khusus `attendance_logs`.

**File:** `AttendanceController.php` (checkIn/checkOut, `logAttempt`), `Admin/AnalysisController.php::latency` (±122)

**Langkah:**
- [ ] 1. Validasi `inference_time_ms` & `device_model` pada request check-in/out.
- [ ] 2. Simpan kedua nilai ke `attendance_logs` (dan/atau `attendances`).
- [ ] 3. Pastikan `logAttempt` menulis kolom tersebut.
- [ ] 4. Uji: endpoint latency menghasilkan avg/min/max/P95/per-device.

## [ ] R-07 — Pipeline data uji simultan (concurrent_level)
**File:** `Admin/AnalysisController.php::simultaneousTest` (±221)
**Langkah:**
- [ ] 1. Tentukan mekanisme: header/param uji ATAU load test eksternal (k6/JMeter).
- [ ] 2. Saat sesi uji, tulis `metadata->concurrent_level`, `metadata->success`, `metadata->latency_ms` ke `attendance_logs`.
- [ ] 3. Jalankan skenario 20/30/40 pengguna.
- [ ] 4. Pastikan H-05 (double recalculation) sudah diperbaiki agar beban valid.
- [ ] 5. Uji: endpoint simultaneous-test menampilkan response time & success rate per level.

## [x] R-08 — Lengkapi data perbandingan konvensional ✅ SELESAI
> `conventionalComparison` kini menghitung `avg_duration_minutes` dari kolom `durasi_efektif_menit` (diisi saat check-out) dengan `COALESCE` fallback ke `TIMESTAMPDIFF(MINUTE, checkin_time, checkout_time)` untuk record lama, plus field `with_checkout` (jumlah record yang sudah check-out). Karena C-02 & H-03 sudah selesai, `checkin/checkout_time` & durasi efektif benar-benar terisi. Sisa: pengisian data konvensional (kertas) via `storeConventionalData` saat sesi penelitian.

**File:** `Admin/AnalysisController.php::conventionalComparison`, `storeConventionalData`
**Langkah:**
- [x] 1. C-02 & H-03 selesai → `checkin_time`/`checkout_time` & durasi efektif terisi.
- [~] 2. Template/SOP input data konvensional (kertas) disiapkan saat sesi pengambilan data.
- [x] 3. `avg_duration_minutes` memakai `durasi_efektif_menit` + fallback `TIMESTAMPDIFF` (tidak null saat ada check-out) + `with_checkout`.
- [x] 4. Struktur respons siap mengisi tabel perbandingan konvensional vs sistem.

## [x] R-09 — Aktifkan check-out (durasi kehadiran efektif) ✅ SELESAI
> Status check-in kini tampil di Home (H-03) sehingga tombol check-out muncul; check-out online terkirim (C-02). `AttendanceController@checkOut` menghitung `durasi_efektif_menit = minutesBetween(checkin_time, actualCheckoutTime)` dan menyimpannya beserta `alpha_menit` (termasuk alpha pulang awal). Jalur offline (`OfflineSyncController`) juga menghitung durasi efektif.

**File:** `AttendanceController.php::checkOut` (±306-320), terkait H-03 & C-02
**Langkah:**
- [x] 1. Status check-in tampil di Home (H-03).
- [x] 2. Check-out online terkirim (C-02).
- [x] 3. `durasi_efektif_menit` dihitung & disimpan saat check-out (online & offline).
- [~] 4. Uji end-to-end check-in → check-out → durasi tersimpan: menunggu sesi device nyata (kode sudah lengkap).

## [x] R-10 — Selaraskan definisi & akurasi early warning SP ✅ SELESAI
> H-01 (alpha pulang awal) & C-05 (alpha offline) selesai → akumulasi alpha benar. Basis early warning ditetapkan = **akumulasi jam alpha** (`total_alpha_jam`), konsisten dengan `AlphaAccumulationService`. `Mahasiswa/DashboardController` kini mengembalikan blok `sp_early_warning` (current_level, next_level, next_threshold_jam, total_alpha_jam, progress_persen, is_approaching, warning_code via `isApproachingNextLevel` ≥80%). Pemetaan ke definisi proposal (persentase kehadiran) dijelaskan di laporan.

**File:** `DashboardController.php` (±89-150), `AlphaAccumulationService::isApproachingNextLevel`
**Langkah:**
- [x] 1. H-01 (alpha pulang awal) & C-05 (alpha offline) selesai.
- [x] 2. Basis early warning ditetapkan = akumulasi jam alpha (`total_alpha_jam`).
- [x] 3. Implementasi diselaraskan + blok `sp_early_warning` diekspos di dashboard (pemetaan ke proposal dicatat di laporan).
- [~] 4. Uji SP1/SP2/SP3 muncul sesuai akumulasi: menunggu data attendance nyata (logika & threshold sudah benar).

---

# URUTAN PENGERJAAN (REKOMENDASI)

> Urutan ini menjaga agar dependensi antar-task tidak saling mengunci dan agar data evaluasi penelitian bisa dihasilkan.

1. [x] **C-01 → C-03 → R-03** — hidupkan verifikasi wajah + mock-location nyata (anti-spoofing).
2. [x] **C-02 → H-03 → R-09** — online submit + status check-in/out (durasi efektif).
3. [x] **R-06** — simpan `inference_time_ms`/`device_model` → data latensi.
4. [x] **M-07 + R-02 + H-04** — L2-normalize + satukan threshold (θ).
5. [ ] **R-05** — pipeline label genuine/impostor → FAR/FRR & θ optimal. *(infra siap; tinggal sesi pengambilan data)*
6. [ ] **R-07** — pipeline `concurrent_level` → uji simultan. *(infra siap; tinggal sesi load test)*
7. [x] **H-01 → C-05 → R-10** — akurasi akumulasi alpha & early warning SP.
8. [x] **C-04** — perbaiki offline sync (endpoint & payload).
9. [x] **H-05 → H-06** — rapikan double/duplicate recalculation.
10. [x] **H-02** — radius geofence custom.
11. [x] **R-01** — kebijakan privasi foto (selaraskan klaim proposal).
12. [x] **R-08** — lengkapi data perbandingan konvensional.
13. [x] **M-01..M-06** — hardening MEDIUM.
14. [x] **D-01 → D-02** — sinkronisasi dokumentasi API.
15. [x] **L-01..L-05** — perbaikan LOW & UX.

---

> Setelah menyelesaikan sebuah task, perbarui checkbox `[ ]` → `[x]` dan kolom "Selesai" pada tabel RINGKASAN PROGRESS. Kerjakan satu per satu sesuai urutan di atas, bro.
