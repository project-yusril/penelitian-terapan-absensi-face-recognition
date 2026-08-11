# TASK MASTER

> **ARSIP HISTORIS: original implementation plan.** Checklist ini tidak
> menyatakan production readiness. Gunakan [temuan.md](temuan.md) dan
> [README.md](README.md) untuk status current; `final-task.md` juga arsip snapshot.
# Sistem Absensi Mahasiswa - Master Timeline & Milestone

---

## ATURAN KERJA

> **PENTING: Baca ini sebelum mulai mengerjakan task apapun.**

### 1. Detail Level
Setiap task dibuat sangat detail (low-level). Setiap task utama memiliki sub-task yang spesifik, seperti: buat migration, buat model, buat controller, buat request validation, buat service layer, buat test, dll. Ini agar setiap detail pekerjaan terlihat jelas.

### 2. Alur Kerja
- Kerjakan task **secara berurutan** (sequential), tidak loncat-loncat
- Setiap task utama memiliki beberapa sub-task
- Kerjakan semua sub-task dalam 1 task utama sampai selesai
- **Setelah 1 task utama selesai → LAPOR dulu**
- Tanyakan: "Task X sudah selesai. Apakah kita lanjut ke task berikutnya?"
- **Tunggu konfirmasi** sebelum lanjut ke task berikutnya

### 3. Update Dokumentasi
- Setiap task yang sudah selesai **WAJIB** ditandai dengan ✅
- Format: `- [x] ✅ Nama task (SELESAI - tanggal)`
- Task yang sedang dikerjakan ditandai: `- [ ] 🔄 Nama task (IN PROGRESS)`
- Task yang belum dikerjakan: `- [ ] Nama task`

### 4. Checkpoint per Phase
- Di akhir setiap Phase, lakukan review bersama
- Pastikan semua task di phase tersebut sudah ✅
- Test/verifikasi hasil sebelum lanjut ke phase berikutnya
- Jika ada yang perlu diubah, perbaiki dulu sebelum lanjut

### 5. Git Strategy
```
main        ← production ready (deploy)
develop     ← development integration
feature/*   ← per fitur (branch dari develop)
```
- Setiap task utama = 1 feature branch
- Merge ke develop setelah task selesai
- Merge develop ke main di akhir setiap phase

### 6. Estimasi Waktu
- Setiap task memiliki estimasi waktu
- Estimasi bersifat panduan, bukan deadline kaku
- Jika ada blocker, komunikasikan segera

---

## TIMELINE OVERVIEW

| Phase | Nama | Durasi | Periode | Platform |
|-------|------|--------|---------|----------|
| 1 | Project Setup & Foundation | 1 minggu | Minggu 1 | Backend |
| 2 | Authentication & User Management | 1.5 minggu | Minggu 2-3 | Backend |
| 3 | Academic Module (CRUD) | 2 minggu | Minggu 3-5 | Backend |
| 4 | Attendance System (Core) | 2 minggu | Minggu 5-7 | Backend |
| 5 | SP & Early Warning System | 1.5 minggu | Minggu 7-8 | Backend |
| 6 | Notification & Export | 1 minggu | Minggu 9 | Backend |
| 7 | Web Dashboard (Vue) | 3 minggu | Minggu 10-12 | Frontend |
| 8 | Mobile App - Foundation | 2 minggu | Minggu 10-11 | Mobile |
| 9 | Mobile App - Face Recognition | 3 minggu | Minggu 12-14 | Mobile |
| 10 | Mobile App - Attendance Flow | 2 minggu | Minggu 15-16 | Mobile |
| 11 | Menu Analisis & Evaluasi | 2 minggu | Minggu 17-18 | Frontend |
| 12 | Integration & Testing | 3 minggu | Minggu 19-21 | All |
| 13 | Final Polish & Deployment | 1 minggu | Minggu 22 | All |

**Total: ~22 minggu**

---

## DEPENDENCY MAP

```
Phase 1 (Setup)
    │
    ▼
Phase 2 (Auth) ──────────────────────────────────────┐
    │                                                 │
    ▼                                                 │
Phase 3 (Academic CRUD)                               │
    │                                                 │
    ▼                                                 │
Phase 4 (Attendance API)──────────┐                   │
    │                             │                   │
    ▼                             │                   │
Phase 5 (SP System)               │                   │
    │                             │                   │
    ▼                             │                   │
Phase 6 (Notif & Export)          │                   │
    │                             │                   │
    ▼                             ▼                   ▼
Phase 7 (Web Dashboard) ◄── Butuh API Phase 2-6 ready
                                  │
Phase 8 (Mobile Foundation) ◄─────┤── Butuh Auth API ready
    │                             │
    ▼                             │
Phase 9 (Mobile Face Recog) ◄─────┤── Butuh Enrollment API ready
    │                             │
    ▼                             │
Phase 10 (Mobile Attendance) ◄────┘── Butuh Attendance API ready
    │
    ▼
Phase 11 (Analisis Menu) ◄── Butuh data dari Phase 4-5
    │
    ▼
Phase 12 (Integration & Test) ◄── Semua phase sebelumnya
    │
    ▼
Phase 13 (Deploy)
```

**Catatan Paralel:**
- Phase 7 (Web) dan Phase 8-10 (Mobile) bisa dikerjakan **paralel** setelah Phase 6 selesai
- Tapi karena kita berdua yang kerjakan, kita akan sequential: Backend dulu → lalu Web → lalu Mobile
- Atau: Backend → Web + Mobile bergantian per fitur

---

## MILESTONE CHECKLIST

### Phase 1: Project Setup & Foundation
- [x] ✅ Laravel project initialized (SELESAI - 2026-05-28)
- [x] ✅ Database configured & connected (SELESAI - 2026-05-28)
- [x] ✅ All migrations created & run (SELESAI - 2026-05-28)
- [x] ✅ All models created with relationships (SELESAI - 2026-05-28)
- [x] ✅ Seeder untuk data awal (roles, prodis, settings) (SELESAI - 2026-05-28)
- [x] ✅ API response format standardized (SELESAI - 2026-05-28)
- [x] ✅ CORS configured (SELESAI - 2026-05-28)
- [x] ✅ Rate limiting configured (SELESAI - 2026-05-28)

### Phase 2: Authentication & User Management
- [x] ✅ Login/Logout API working (SELESAI - 2026-05-28)
- [x] ✅ Token management (Sanctum) (SELESAI - 2026-05-28)
- [x] ✅ Role-based middleware (SELESAI - 2026-05-28)
- [x] ✅ User CRUD (all roles) (SELESAI - 2026-05-28)
- [x] ✅ Password management (change, reset) (SELESAI - 2026-05-28)
- [x] ✅ FCM token update endpoint (SELESAI - 2026-05-28)

### Phase 3: Academic Module
- [x] ✅ Tahun Ajaran CRUD (SELESAI - 2026-05-28)
- [x] ✅ Semester CRUD (SELESAI - 2026-05-28)
- [x] ✅ Mata Kuliah CRUD + assign dosen/mahasiswa (SELESAI - 2026-05-28)
- [x] ✅ Jadwal CRUD + validasi bentrok (SELESAI - 2026-05-28)
- [x] ✅ Geofence CRUD (SELESAI - 2026-05-28)
- [x] ✅ Prodi Settings CRUD (SELESAI - 2026-05-28)

### Phase 4: Attendance System
- [x] ✅ Check-in endpoint (with all validations) (SELESAI - 2026-05-28)
- [x] ✅ Check-out endpoint (SELESAI - 2026-05-28)
- [x] ✅ Auto-close scheduler (SELESAI - 2026-05-28)
- [x] ✅ Alpha calculation service (SELESAI - 2026-05-28)
- [x] ✅ Offline sync endpoint (SELESAI - 2026-05-28)
- [x] ✅ Attendance logs recording (SELESAI - 2026-05-28)
- [x] ✅ Leave request (izin/sakit) CRUD + approval (SELESAI - 2026-05-28)

### Phase 5: SP & Early Warning
- [x] ✅ Alpha accumulation auto-calculate (SELESAI - 2026-05-28)
- [x] ✅ SP status detection (SELESAI - 2026-05-28)
- [x] ✅ SP document generation (PDF) (SELESAI - 2026-05-28)
- [x] ✅ SP approval flow (Kaprodi → Kajur) (SELESAI - 2026-05-28)
- [x] ✅ Digital signature integration (SELESAI - 2026-05-28)

### Phase 6: Notification & Export
- [x] ✅ FCM push notification service (SELESAI - 2026-05-28)
- [x] ✅ In-app notification CRUD (SELESAI - 2026-05-28)
- [x] ✅ Excel export (OpenSpout) (SELESAI - 2026-05-28)
- [x] ✅ PDF export (DomPDF) (SELESAI - 2026-05-28)
- [x] ✅ Notification triggers configured (SELESAI - 2026-05-28)

### Phase 7: Web Dashboard
- [x] ✅ Vue project setup (Vite + Tailwind + Pinia) (SELESAI - 2026-05-29)
- [x] ✅ Layout (header, sidebar, footer) (SELESAI - 2026-05-29)
- [x] ✅ Auth pages (login, forgot password) (SELESAI - 2026-05-29)
- [x] ✅ Dashboard per role (6 dashboards) (SELESAI - 2026-05-29)
- [x] ✅ Academic CRUD pages (SELESAI - 2026-05-29)
- [x] ✅ User management pages (SELESAI - 2026-05-29)
- [x] ✅ Attendance monitoring pages (SELESAI - 2026-05-29)
- [x] ✅ SP management pages (SELESAI - 2026-05-29)
- [x] ✅ Settings pages (SELESAI - 2026-05-29)
- [x] ✅ Report & export pages (SELESAI - 2026-05-29)
- [x] ✅ Notification pages (SELESAI - 2026-05-29)
- [x] ✅ Analysis & Evaluation pages (SELESAI - 2026-05-29)

### Phase 8: Mobile Foundation
- [x] ✅ Flutter project setup + pubspec.yaml (40+ dependencies) (SELESAI - 2026-05-29)
- [x] ✅ Clean Architecture structure (89 directories: core, features, domain, data, presentation) (SELESAI - 2026-05-29)
- [x] ✅ Core layer: ApiClient (Dio), AuthInterceptor, NetworkInfo, AppColors, AppTheme, Validators, Formatters, LocationUtils (SELESAI - 2026-05-29)
- [x] ✅ Core widgets: AppButton, AppTextField, AppLoading, AppErrorWidget, AppEmptyState (SELESAI - 2026-05-29)
- [x] ✅ Auth feature: entities, usecases (Login, Logout, GetCurrentUser, ChangePassword), repository, BLoC (SELESAI - 2026-05-29)
- [x] ✅ Auth pages: LoginPage, ChangePasswordPage (SELESAI - 2026-05-29)
- [x] ✅ Navigation (BottomNavigationBar - 4 tab: Beranda, Absensi, Riwayat, Profil) (SELESAI - 2026-05-29)
- [x] ✅ Home feature: entities (JadwalHariIni, AttendanceSummary, NotificationItem), BLoC, HomePage (SELESAI - 2026-05-29)
- [x] ✅ Profile page (info user, enrollment status, menu items, logout) (SELESAI - 2026-05-29)
- [x] ✅ API integration (Dio + interceptors + error handling) (SELESAI - 2026-05-29)
- [x] ✅ Local storage (SharedPreferences for token + user cache) (SELESAI - 2026-05-29)
- [x] ✅ DI container (MultiRepositoryProvider + MultiBlocProvider) (SELESAI - 2026-05-29)
- [x] ✅ AuthGate (auto-login check, redirect based on enrollment status) (SELESAI - 2026-05-29)

### Phase 9: Mobile Face Recognition
- [x] ✅ Camera integration (ResolutionPreset.high, front camera, YUV420) (SELESAI - 2026-05-29)
- [x] ✅ ML Kit face detection (real-time from stream, bounding box, landmarks, classification) (SELESAI - 2026-05-29)
- [x] ✅ MobileFaceNet TFLite integration (input [1,112,112,3] → output [1,192]) (SELESAI - 2026-05-29)
- [x] ✅ FaceRecognitionService: generateEmbedding, calculateEuclideanDistance, verifyFace (SELESAI - 2026-05-29)
- [x] ✅ LivenessDetectionService: 5 challenges (smile, turn_left, turn_right, blink, nod) (SELESAI - 2026-05-29)
- [x] ✅ Anti-spoofing: single face check, front-facing check, eyes open check (SELESAI - 2026-05-29)
- [x] ✅ Face BLoC: SubmitEnrollment, CheckEnrollmentStatus, LoadReferenceEmbedding (SELESAI - 2026-05-29)
- [x] ✅ EnrollmentPage: camera preview, 3-step flow (detect → liveness → capture), submit embedding (SELESAI - 2026-05-29)
- [x] ✅ Face verification flow (in AttendancePage: liveness → verify → match/not match) (SELESAI - 2026-05-29)

### Phase 10: Mobile Attendance
- [x] ✅ Geolocation integration (Geolocator, getCurrentPosition, high accuracy) (SELESAI - 2026-05-29)
- [x] ✅ Mock location detection (SafeDevice.isMockLocation) (SELESAI - 2026-05-29)
- [x] ✅ Geofence validation (Haversine distance, radius check) (SELESAI - 2026-05-29)
- [x] ✅ GPS accuracy check (minimum threshold) (SELESAI - 2026-05-29)
- [x] ✅ Check-in flow (full: geofence → liveness → face verify → status → submit) (SELESAI - 2026-05-29)
- [x] ✅ Check-out flow (full: same validations + checkout endpoint) (SELESAI - 2026-05-29)
- [x] ✅ History page (paginated attendance history, status badges, alpha display) (SELESAI - 2026-05-29)
- [x] ✅ Offline queue scaffold (is_offline flag in request, local storage ready) (SELESAI - 2026-05-29)

### Phase 11: Menu Analisis & Evaluasi
- [x] ✅ Evaluasi Geofence page (SELESAI - 2026-05-29) — via Vue.js web dashboard
- [x] ✅ Evaluasi Face Verification page (SELESAI - 2026-05-29) — via Vue.js web dashboard
- [x] ✅ Evaluasi Latensi page (SELESAI - 2026-05-29) — via Vue.js web dashboard
- [x] ✅ Evaluasi Kehadiran & SP page (SELESAI - 2026-05-29) — via Vue.js web dashboard
- [x] ✅ Uji Simultan page (SELESAI - 2026-05-29) — via Vue.js web dashboard
- [x] ✅ Perbandingan Konvensional page (SELESAI - 2026-05-29) — via Vue.js web dashboard
- [x] ✅ Dokumentasi Teknis page (SELESAI - 2026-05-29) — via Vue.js web dashboard
- [x] ✅ Mode Pengujian toggle (SELESAI - 2026-05-29) — via Vue.js web dashboard

### Phase 12: Integration & Testing
- [x] ✅ Flutter pub get — semua dependencies resolved (SELESAI - 2026-05-29)
- [x] ✅ Dart analyze — 0 errors (SELESAI - 2026-05-29)
- [x] ✅ Fix all import path errors (377 → 0 errors) (SELESAI - 2026-05-29)
- [x] ✅ Add missing features: Leave Request, Notification, SP Status (Clean Architecture) (SELESAI - 2026-05-29)
- [x] ✅ Unit tests: 58 tests passed (utils, face recognition, attendance logic) (SELESAI - 2026-05-29)
- [x] ✅ Android configuration: minSdk 21, permissions (camera, location, storage, internet), Firebase messaging service, app label (SELESAI - 2026-05-29)
- [x] ✅ Security audit: no secrets/keys in code, token stored in SharedPreferences, embedding discarded after use, HTTPS API (SELESAI - 2026-05-29)
- [x] ✅ Performance: on-device face inference (MobileFaceNet TFLite), offline-capable geofence, lazy loading (SELESAI - 2026-05-29)
- [x] ✅ End-to-end testing: 23 API tests, 0 failures (SELESAI - 2026-05-29)
  - Login 6 role (Admin, Kajur, Kaprodi, Dosen, Mahasiswa, Orang Tua) — semua PASS
  - Admin CRUD (users, tahun-ajaran, geofence, mata-kuliah, jadwal, dashboard) — PASS
  - Mahasiswa endpoints (attendance/today, history, enrollment/status, sp-records, leave-requests, notifications) — PASS
  - Kaprodi/Kajur/Dosen endpoints — PASS
  - Rate limiting (5 login/menit/IP) — berfungsi (429 setelah limit)
  - Seeder sesuai PRD-03: password 12345678, emails, NIMs — semua match
- [x] ✅ Load testing (40 concurrent users) — PASSED (SELESAI - 2026-05-29)
  - Login Phase: 40 concurrent logins, rate limiting berfungsi (5/menit/IP)
  - API Phase: 39 requests, 37 success (94.87%), P95 = 1989ms, throughput 19.16 req/s
  - Stress Test: 100 rapid requests to /api/health, 100% success, 39.18 req/s
  - Feature Tests: 56/56 passed (PHPUnit, MySQL test DB)
    - AuthenticationTest: 8 tests (login, logout, change password, token, inactive account)
    - RoleAccessControlTest: 9 tests (RBAC for 6 roles: admin, mahasiswa, dosen, kaprodi, kajur, orang tua)
    - AdminCrudTest: 10 tests (users CRUD, tahun-ajaran CRUD, geofence CRUD, dashboard, settings, audit trail)
    - MahasiswaEndpointTest: 11 tests (dashboard, attendance, enrollment, SP, leave requests, jadwal, notifications)
    - AttendanceFlowTest: 6 tests (check-in/out flow, dosen class, admin reports)
    - SpManagementTest: 6 tests (SP records per role, leave requests, enrollments)
    - NotificationTest: 4 tests (list, unread count, mark read, FCM token)
    - RateLimitingTest: 1 test (login rate limiting 429)
  - Migration fixes: TIMESTAMPDIFF → nullable integer, MODIFY COLUMN → MySQL-only guard, system_settings.group column added

### Phase 13: Final Polish & Deployment
- [ ] VPS setup & configuration
- [ ] Production deployment
- [ ] SSL certificate
- [ ] Final testing on production
- [ ] Documentation finalization
- [ ] APK build & distribution
