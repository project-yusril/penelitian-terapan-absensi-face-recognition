# TASK MOBILE

> **ARSIP HISTORIS.** SharedPreferences credential, queue lama, direct
> attendance, dan platform claims di dokumen ini telah disupersede. Gunakan
> [`frontend/README.md`](../frontend/README.md), [CURRENT-API.md](CURRENT-API.md),
> dan [DEPLOYMENT.md](DEPLOYMENT.md).
# Sistem Absensi Mahasiswa - Flutter Mobile App Tasks (Low-Level Detail)

---

## ATURAN KERJA

> Setiap task dibuat sangat detail (low-level). Kerjakan secara berurutan.
> Setelah 1 task utama selesai → LAPOR → tunggu konfirmasi → lanjut task berikutnya.
> Task selesai ditandai ✅. Task in-progress ditandai 🔄.

---

## PHASE 8: MOBILE FOUNDATION (Clean Architecture)
**Estimasi: 2 minggu**

### Task 8.1: Inisialisasi Project Flutter
- [x] ✅ Setup `pubspec.yaml` dengan 40+ dependencies (SELESAI - 2026-05-29)
  - [x] ✅ `flutter_bloc` + `equatable` (state management)
  - [x] ✅ `dio` (HTTP client)
  - [x] ✅ `shared_preferences` + `hive` + `hive_flutter` (local storage)
  - [x] ✅ `geolocator` (GPS)
  - [x] ✅ `camera` + `google_mlkit_face_detection` (face detection)
  - [x] ✅ `tflite_flutter` (MobileFaceNet model)
  - [x] ✅ `image` (image processing)
  - [x] ✅ `device_info_plus` + `safe_device` (device info, mock detection)
  - [x] ✅ `firebase_core` + `firebase_messaging` (push notification)
  - [x] ✅ `connectivity_plus` (network check)
  - [x] ✅ `permission_handler` (runtime permissions)
  - [x] ✅ `dartz` (functional programming)
  - [x] ✅ `file_picker` + `path_provider` (file handling)
- [x] ✅ Run `flutter pub get` — semua dependencies resolved (SELESAI - 2026-05-29)

### Task 8.2: Clean Architecture Folder Structure
- [x] ✅ Buat 89 directories (SELESAI - 2026-05-29)
  - [x] ✅ `lib/core/constants/` (api_constants, app_constants)
  - [x] ✅ `lib/core/errors/` (exceptions, failures)
  - [x] ✅ `lib/core/network/` (api_client, interceptors, network_info)
  - [x] ✅ `lib/core/theme/` (app_colors, app_theme)
  - [x] ✅ `lib/core/utils/` (validators, formatters, location_utils)
  - [x] ✅ `lib/core/widgets/` (app_button, app_text_field, app_loading)
  - [x] ✅ `lib/features/auth/` (domain, data, presentation)
  - [x] ✅ `lib/features/home/` (domain, data, presentation)
  - [x] ✅ `lib/features/attendance/` (domain, data, presentation)
  - [x] ✅ `lib/features/face_recognition/` (domain, data, presentation)
  - [x] ✅ `lib/features/profile/` (domain, data, presentation)
  - [x] ✅ `lib/features/history/` (domain, data, presentation)
  - [x] ✅ `lib/features/leave_request/` (domain, data, presentation)
  - [x] ✅ `lib/features/notification/` (domain, data, presentation)
  - [x] ✅ `lib/features/sp_status/` (domain, data, presentation)
  - [x] ✅ `lib/features/shell/` (main shell with bottom nav)

### Task 8.3: Core Layer Implementation
- [x] ✅ `ApiConstants` — semua endpoint dari PRD-04 (SELESAI - 2026-05-29)
- [x] ✅ `AppConstants` — threshold, timeout, liveness challenges, SP thresholds (SELESAI - 2026-05-29)
- [x] ✅ `AppColors` — design system dari PRD-06 (#4F7CAC primary, status colors, SP colors) (SELESAI - 2026-05-29)
- [x] ✅ `AppTheme` — Material 3 theme dengan design system (SELESAI - 2026-05-29)
- [x] ✅ `ApiClient` — Dio wrapper + error handling (401, 422, 429, timeout, network) (SELESAI - 2026-05-29)
- [x] ✅ `AuthInterceptor` — inject Bearer token + auto-logout on 401 (SELESAI - 2026-05-29)
- [x] ✅ `NetworkInfo` — connectivity check via connectivity_plus (SELESAI - 2026-05-29)
- [x] ✅ `Exceptions` — ServerException, AuthException, GeofenceException, MockLocationException, etc (SELESAI - 2026-05-29)
- [x] ✅ `Failures` — ServerFailure, AuthFailure, GeofenceFailure, NetworkFailure, etc (SELESAI - 2026-05-29)
- [x] ✅ `Validators` — email, password, NIM, phone, required (SELESAI - 2026-05-29)
- [x] ✅ `Formatters` — date, time, duration, distance, percentage, greeting, status labels (SELESAI - 2026-05-29)
- [x] ✅ `LocationUtils` — haversineDistance, isWithinGeofence (SELESAI - 2026-05-29)

### Task 8.4: Core Widgets
- [x] ✅ `AppButton` — primary, loading state, icon support (SELESAI - 2026-05-29)
- [x] ✅ `AppOutlineButton` — outlined variant (SELESAI - 2026-05-29)
- [x] ✅ `AppTextField` — label, hint, error, prefix/suffix icon, validator (SELESAI - 2026-05-29)
- [x] ✅ `AppLoading` — spinner + message (SELESAI - 2026-05-29)
- [x] ✅ `AppErrorWidget` — error message + retry button (SELESAI - 2026-05-29)
- [x] ✅ `AppEmptyState` — icon + title + subtitle (SELESAI - 2026-05-29)

### Task 8.5: Auth Feature (Clean Architecture)
- [x] ✅ Domain Layer:
  - [x] ✅ `User` entity (id, nama, email, nim, nidn, prodi, roles, enrollment_status, mustChangePassword) (SELESAI - 2026-05-29)
  - [x] ✅ `AuthRepository` abstract class (SELESAI - 2026-05-29)
  - [x] ✅ Use cases: `Login`, `Logout`, `GetCurrentUser`, `ChangePassword` (SELESAI - 2026-05-29)
- [x] ✅ Data Layer:
  - [x] ✅ `UserModel` (fromJson, toJson) (SELESAI - 2026-05-29)
  - [x] ✅ `AuthRemoteDataSource` (login, logout, me, changePassword, updateFcmToken, updateProfile) (SELESAI - 2026-05-29)
  - [x] ✅ `AuthLocalDataSource` (token CRUD, user cache via SharedPreferences) (SELESAI - 2026-05-29)
  - [x] ✅ `AuthRepositoryImpl` (offline-first: try remote → fallback cache) (SELESAI - 2026-05-29)
- [x] ✅ Presentation Layer:
  - [x] ✅ `AuthBloc` (LoginRequested, LogoutRequested, CheckAuthStatus, ChangePasswordRequested, UpdateProfileRequested) (SELESAI - 2026-05-29)
  - [x] ✅ `AuthEvent` + `AuthState` (Equatable) (SELESAI - 2026-05-29)
  - [x] ✅ `LoginPage` (form, validation, BlocListener for navigation) (SELESAI - 2026-05-29)
  - [x] ✅ `ChangePasswordPage` (old + new + confirm password) (SELESAI - 2026-05-29)

### Task 8.6: Home Feature
- [x] ✅ Domain: `JadwalHariIni` entity (jadwalId, mataKuliah, dosen, jamMulai/Selesai, geofence, attendanceStatus, isOngoing) (SELESAI - 2026-05-29)
- [x] ✅ Domain: `AttendanceSummary` entity (totalHadir, totalAlpha, persentaseKehadiran, totalAlphaJam, spStatus, spThreshold) (SELESAI - 2026-05-29)
- [x] ✅ Domain: `NotificationItem` entity (id, title, body, type, isRead, createdAt) (SELESAI - 2026-05-29)
- [x] ✅ Domain: `HomeRepository` abstract (SELESAI - 2026-05-29)
- [x] ✅ Data: `HomeRemoteDataSource` (getTodaySchedule, getAttendanceSummary, getRecentNotifications) (SELESAI - 2026-05-29)
- [x] ✅ Data: `HomeRepositoryImpl` (SELESAI - 2026-05-29)
- [x] ✅ Presentation: `HomeBloc` (LoadHomeData, RefreshHomeData) (SELESAI - 2026-05-29)
- [x] ✅ Presentation: `HomePage` (greeting, summary cards, alpha progress bar, jadwal list, notifikasi) (SELESAI - 2026-05-29)

### Task 8.7: Main Shell & Navigation
- [x] ✅ `MainShell` (BottomNavigationBar - 4 tab: Beranda, Absensi, Riwayat, Profil) (SELESAI - 2026-05-29)
- [x] ✅ `AuthGate` (auto-login check → redirect ke login/home/change-password/enrollment) (SELESAI - 2026-05-29)
- [x] ✅ Route setup di `main.dart` (/, /login, /home, /change-password, /enrollment, /history, /notifications) (SELESAI - 2026-05-29)

### Task 8.8: Profile Page
- [x] ✅ Profile header (avatar, nama, NIM/NIDN, prodi, kelas) (SELESAI - 2026-05-29)
- [x] ✅ Enrollment status card (status + warna + link ke enrollment page) (SELESAI - 2026-05-29)
- [x] ✅ Menu items (Ubah Password, Tentang Aplikasi) (SELESAI - 2026-05-29)
- [x] ✅ Logout button (with confirmation dialog) (SELESAI - 2026-05-29)

### Task 8.9: DI Container & App Setup
- [x] ✅ `main.dart` — MultiRepositoryProvider + MultiBlocProvider (SELESAI - 2026-05-29)
- [x] ✅ Register: ApiClient, AuthRepository, HomeRepository, NetworkInfo (SELESAI - 2026-05-29)
- [x] ✅ Register: AuthBloc, HomeBloc, FaceBloc (SELESAI - 2026-05-29)
- [x] ✅ MaterialApp with routes + theme (SELESAI - 2026-05-29)

---

## PHASE 9: MOBILE FACE RECOGNITION
**Estimasi: 3 minggu**

### Task 9.1: Face Recognition Service
- [x] ✅ `FaceRecognitionService` (SELESAI - 2026-05-29)
  - [x] ✅ `initialize()` — load MobileFaceNet TFLite model from assets (SELESAI - 2026-05-29)
  - [x] ✅ `generateEmbedding(imageBytes, face)` — crop face → resize 112×112 → normalize → run model → 192-dim (SELESAI - 2026-05-29)
  - [x] ✅ `calculateEuclideanDistance(e1, e2)` — sqrt(sum((e_i - t_i)^2)) (SELESAI - 2026-05-29)
  - [x] ✅ `verifyFace(imageBytes, face, refEmbedding, threshold)` — full pipeline + timing (SELESAI - 2026-05-29)
  - [x] ✅ `_imageToFloatList(image)` — normalize: (x - 127.5) / 127.5 (SELESAI - 2026-05-29)

### Task 9.2: Liveness Detection Service
- [x] ✅ `LivenessDetectionService` (SELESAI - 2026-05-29)
  - [x] ✅ `getRandomChallenge()` — shuffle 5 challenges (SELESAI - 2026-05-29)
  - [x] ✅ `checkChallenge(face, challenge)` — dispatch to specific check (SELESAI - 2026-05-29)
  - [x] ✅ `_checkSmile(face)` — smilingProbability > 0.7 (SELESAI - 2026-05-29)
  - [x] ✅ `_checkTurnLeft(face)` — headEulerAngleY < -20° (SELESAI - 2026-05-29)
  - [x] ✅ `_checkTurnRight(face)` — headEulerAngleY > 20° (SELESAI - 2026-05-29)
  - [x] ✅ `_checkBlink(face)` — eyeOpenProbability < 0.3 (SELESAI - 2026-05-29)
  - [x] ✅ `_checkNod(face)` — headEulerAngleX change > 15° (SELESAI - 2026-05-29)
  - [x] ✅ `hasSingleFace(faces)` — anti-multiple faces (SELESAI - 2026-05-29)
  - [x] ✅ `isFaceFacingFront(face)` — euler angle within range (SELESAI - 2026-05-29)
  - [x] ✅ `areEyesOpen(face)` — eye open probability > 0.5 (SELESAI - 2026-05-29)

### Task 9.3: Face BLoC
- [x] ✅ `FaceEvent` (StartEnrollment, SubmitEnrollment, CheckEnrollmentStatus, LoadReferenceEmbedding, FaceVerificationCompleted, ResetFaceState) (SELESAI - 2026-05-29)
- [x] ✅ `FaceState` (FaceInitial, FaceLoading, EnrollmentReady, EnrollmentSubmitted, EnrollmentStatusLoaded, ReferenceEmbeddingLoaded, FaceVerificationResult, FaceError) (SELESAI - 2026-05-29)
- [x] ✅ `FaceBloc` (handle all events, store reference embedding + threshold) (SELESAI - 2026-05-29)

### Task 9.4: Enrollment Page
- [x] ✅ Camera initialization (front camera, ResolutionPreset.high, YUV420) (SELESAI - 2026-05-29)
- [x] ✅ Real-time face detection via ML Kit (startImageStream) (SELESAI - 2026-05-29)
- [x] ✅ 3-step UI: (1) Detect face → (2) Liveness challenge → (3) Capture + submit (SELESAI - 2026-05-29)
- [x] ✅ Face frame overlay (oval border, green when detected) (SELESAI - 2026-05-29)
- [x] ✅ Step indicators (numbered circles with labels) (SELESAI - 2026-05-29)
- [x] ✅ Liveness challenge display (instruction text + countdown) (SELESAI - 2026-05-29)
- [x] ✅ Auto-capture on liveness pass → generate embedding → submit to API (SELESAI - 2026-05-29)
- [x] ✅ BlocListener: navigate to home on success (SELESAI - 2026-05-29)

### Task 9.5: Face Verification Flow (di dalam AttendancePage)
- [x] ✅ Load reference embedding dari API (via FaceBloc) (SELESAI - 2026-05-29)
- [x] ✅ Liveness detection (1 random challenge) (SELESAI - 2026-05-29)
- [x] ✅ Face verification (generate embedding → compare with reference → euclidean distance) (SELESAI - 2026-05-29)
- [x] ✅ Threshold comparison (d < threshold → MATCH) (SELESAI - 2026-05-29)
- [x] ✅ Show result (match → proceed, not match → retry) (SELESAI - 2026-05-29)
- [x] ✅ Privacy: frame & embedding discarded after processing (SELESAI - 2026-05-29)

---

## PHASE 10: MOBILE ATTENDANCE
**Estimasi: 2 minggu**

### Task 10.1: Geolocation & Geofence
- [x] ✅ Location permission request (SELESAI - 2026-05-29)
- [x] ✅ getCurrentPosition (high accuracy) (SELESAI - 2026-05-29)
- [x] ✅ Mock location detection (SafeDevice.isMockLocation) (SELESAI - 2026-05-29)
- [x] ✅ Haversine distance calculation (SELESAI - 2026-05-29)
- [x] ✅ Geofence radius validation (distance <= radius) (SELESAI - 2026-05-29)
- [x] ✅ GPS accuracy check (>= minimum threshold) (SELESAI - 2026-05-29)

### Task 10.2: Attendance Page (Check-in Flow)
- [x] ✅ Step 0: Location validation (SELESAI - 2026-05-29)
  - [x] ✅ Check mock location → reject if detected (SELESAI - 2026-05-29)
  - [x] ✅ Get GPS position (SELESAI - 2026-05-29)
  - [x] ✅ Calculate distance to geofence (SELESAI - 2026-05-29)
  - [x] ✅ Compare with radius → accept/reject (SELESAI - 2026-05-29)
- [x] ✅ Step 1: Liveness detection (1 random challenge) (SELESAI - 2026-05-29)
- [x] ✅ Step 2: Face verification (embedding comparison) (SELESAI - 2026-05-29)
- [x] ✅ Step 3: Submit attendance data to API (SELESAI - 2026-05-29)
- [x] ✅ Status indicators (location ✓, liveness ✓, face ✓) (SELESAI - 2026-05-29)
- [x] ✅ Error handling (location invalid, face not match, network error) (SELESAI - 2026-05-29)

### Task 10.3: Check-out Flow
- [x] ✅ Same validation flow as check-in (SELESAI - 2026-05-29)
- [x] ✅ Uses attendance_id instead of jadwal_id (SELESAI - 2026-05-29)
- [x] ✅ Endpoint: POST /attendance/check-out (SELESAI - 2026-05-29)

### Task 10.4: History Page
- [x] ✅ Paginated attendance history (SELESAI - 2026-05-29)
- [x] ✅ Status badges (Hadir, Alpha, Izin, Sakit, Pending) with colors (SELESAI - 2026-05-29)
- [x] ✅ Display: mata kuliah, tanggal, check-in/out time, alpha menit (SELESAI - 2026-05-29)
- [x] ✅ Pull-to-refresh (SELESAI - 2026-05-29)
- [x] ✅ Infinite scroll pagination (SELESAI - 2026-05-29)

### Task 10.5: Offline Queue & Sync (Scaffold)
- [x] ✅ is_offline flag in attendance request (SELESAI - 2026-05-29)
- [x] ✅ Connectivity check before submit (SELESAI - 2026-05-29)
- [x] ✅ Hive local queue for offline attendance data (SELESAI - 2026-05-29)
  - OfflineQueueItem model (Hive TypeAdapter typeId=0): id, type, data, createdAt, retryCount, status
  - OfflineQueueService: enqueue, markSyncing, markCompleted, markFailed, getPendingItems, retryFailed
  - Max 3 retries per item, auto-remove on completion
  - Types: check_in, check_out, sync_offline
- [x] ✅ Auto-sync when connectivity restored (SELESAI - 2026-05-29)
  - ConnectivityService: listens to connectivity_plus onConnectivityChanged
  - Auto-triggers syncPendingItems() when back online + has pending items
  - Syncs each item to correct API endpoint (check-in/check-out/sync-offline)
  - Sets is_offline=true flag on synced requests
  - Stream<SyncState> for UI updates (idle/syncing/completed)
- [x] ✅ Sync status indicator (SELESAI - 2026-05-29)
  - SyncStatusIndicator widget: shows pending count / syncing spinner / failed count
  - Color-coded: blue=syncing, orange=pending, red=failed
  - Tap to open bottom sheet with full queue details
  - "Sinkron Sekarang" button for manual sync
  - "Coba Ulang yang Gagal" button for retrying failed items
  - Integrated in HomePage (greeting section) + AttendanceTab AppBar

### Task 10.6: Leave Request
- [x] ✅ LeaveRequest feature (domain → data → presentation) (SELESAI - 2026-05-29)
- [x] ✅ Submit izin/sakit form (type dropdown, date range, file upload, keterangan) (SELESAI - 2026-05-29)
- [x] ✅ My leaves list (status badges: pending/approved/rejected) (SELESAI - 2026-05-29)
- [x] ✅ File upload via multipart form (SELESAI - 2026-05-29)

---

## PHASE 12: INTEGRATION & TESTING
**Estimasi: 3 minggu**

### Task 12.1: Fix Compilation Errors
- [x] ✅ Run `dart analyze lib` — 377 errors found (SELESAI - 2026-05-29)
- [x] ✅ Fix all import paths (off by one directory level) (SELESAI - 2026-05-29)
- [x] ✅ Fix type errors (List<dynamic> casts, return type mismatch) (SELESAI - 2026-05-29)
- [x] ✅ Fix catch clause ordering (SELESAI - 2026-05-29)
- [x] ✅ Remove unused imports/variables (SELESAI - 2026-05-29)
- [x] ✅ Final result: 0 errors, 1 warning, 26 info (SELESAI - 2026-05-29)

### Task 12.2: Add Missing Features
- [x] ✅ Leave Request feature (8 files, Clean Architecture) (SELESAI - 2026-05-29)
- [x] ✅ Notification feature (8 files, Clean Architecture) (SELESAI - 2026-05-29)
- [x] ✅ SP Status feature (8 files, Clean Architecture) (SELESAI - 2026-05-29)

### Task 12.3: Unit Tests
- [x] ✅ `test/unit/utils_test.dart` — LocationUtils (haversine, geofence), Validators (email, password, NIM), Formatters (duration, distance, percentage, status labels) (SELESAI - 2026-05-29)
- [x] ✅ `test/unit/face_recognition_test.dart` — Euclidean distance, threshold comparison, normalization, liveness challenge logic (SELESAI - 2026-05-29)
- [x] ✅ `test/unit/attendance_logic_test.dart` — Status determination, alpha calculation, SP thresholds, effective duration (SELESAI - 2026-05-29)
- [x] ✅ Result: 58 tests passed, 0 failures (SELESAI - 2026-05-29)

### Task 12.4: Android Configuration
- [x] ✅ `build.gradle.kts` — minSdk 21, multiDexEnabled, applicationId (SELESAI - 2026-05-29)
- [x] ✅ `AndroidManifest.xml` — permissions (INTERNET, CAMERA, LOCATION, STORAGE, NETWORK, WAKE_LOCK, VIBRATE, BIOMETRIC) (SELESAI - 2026-05-29)
- [x] ✅ Camera feature requirements (camera, camera.front) (SELESAI - 2026-05-29)
- [x] ✅ GPS feature requirement (SELESAI - 2026-05-29)
- [x] ✅ Firebase Messaging Service declaration (SELESAI - 2026-05-29)
- [x] ✅ App label: "Absensi Mahasiswa" (SELESAI - 2026-05-29)

### Task 12.5: Security & Performance Audit
- [x] ✅ No secrets/keys hardcoded in source (SELESAI - 2026-05-29)
- [x] ✅ Token stored in SharedPreferences (cleared on logout) (SELESAI - 2026-05-29)
- [x] ✅ Face embedding discarded after attendance processing (SELESAI - 2026-05-29)
- [x] ✅ HTTPS API communication (SELESAI - 2026-05-29)
- [x] ✅ On-device face inference (no photo sent to server) (SELESAI - 2026-05-29)
- [x] ✅ Mock location detection enabled (SELESAI - 2026-05-29)
- [x] ✅ Lazy loading for TFLite model (initialize on first use) (SELESAI - 2026-05-29)

### Task 12.6: End-to-End Testing (Manual)
- [ ] Login → Home → Check schedule
- [ ] Enrollment flow: camera → liveness → capture → submit → pending
- [ ] Admin approve enrollment → student can now check-in
- [ ] Check-in: geofence → liveness → face verify → status → submit
- [ ] Check-out: same flow → duration calculated
- [ ] History: view past attendance records
- [ ] Leave request: submit izin → admin approve → alpha updated
- [ ] Notifications: receive push, mark read
- [ ] SP status: view alpha accumulation, SP records
- [ ] Logout → login again → data persisted

---

## CATATAN TEKNIS

### Camera Configuration
- Preview: `ResolutionPreset.high` (1280×720)
- Format: YUV420 (for ML processing)
- Photo capture: `takePicture()` — full HP resolution (for enrollment only)

### MobileFaceNet Configuration
- Model file: `assets/mobile_face_net.tflite`
- Input: `[1, 112, 112, 3]` (batch, height, width, RGB)
- Output: `[1, 192]` (embedding vector)
- Normalization: `(pixel - 127.5) / 127.5`

### Privacy & Security
- Frame wajah: DIBUANG dari memori setelah proses
- Embedding saat absensi: TIDAK disimpan (proses di memori lalu dibuang)
- Yang dikirim ke API: distance, threshold, result, inference_time, device_info
- Embedding referensi: disimpan 1x di database saat enrollment

### File Structure (Clean Architecture)
```
lib/
├── core/
│   ├── constants/    (api_constants, app_constants)
│   ├── errors/       (exceptions, failures)
│   ├── network/      (api_client, interceptors, network_info)
│   ├── theme/        (app_colors, app_theme)
│   ├── utils/        (validators, formatters, location_utils)
│   └── widgets/      (app_button, app_text_field, app_loading)
├── features/
│   ├── auth/         (domain → data → presentation) ✅
│   ├── home/         (domain → data → presentation) ✅
│   ├── attendance/   (domain → data → presentation) ✅
│   ├── face_recognition/ (domain → data → presentation) ✅
│   ├── profile/      (domain → data → presentation) ✅
│   ├── history/      (domain → data → presentation) ✅
│   ├── leave_request/ (domain → data → presentation) ✅
│   ├── notification/ (domain → data → presentation) ✅
│   ├── sp_status/    (domain → data → presentation) ✅
│   └── shell/        (main_shell) ✅
├── test/unit/        (58 tests) ✅
└── main.dart         (DI + routes + AuthGate) ✅
```
