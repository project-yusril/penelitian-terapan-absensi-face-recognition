# TASK BACKEND

> **ARSIP HISTORIS.** Versi framework, universal-password seeder, route, dan
> attendance flow di dokumen ini telah berubah. Gunakan
> [CURRENT-API.md](CURRENT-API.md), [CURRENT-ARCHITECTURE.md](CURRENT-ARCHITECTURE.md),
> serta migrations/tests sebagai sumber current.
# Sistem Absensi Mahasiswa - Laravel API Tasks (Low-Level Detail)

---

## ATURAN KERJA

> Setiap task dibuat sangat detail (low-level). Kerjakan secara berurutan.
> Setelah 1 task utama selesai → LAPOR → tunggu konfirmasi → lanjut task berikutnya.
> Task selesai ditandai ✅. Task in-progress ditandai 🔄.

---

## PHASE 1: PROJECT SETUP & FOUNDATION
**Estimasi: 1 minggu**

### Task 1.1: Inisialisasi Project Laravel
- [x] ✅ Install Laravel 11 via composer (`composer create-project laravel/laravel`)
- [x] ✅ Setup `.env` (DB_DATABASE=absensi_mahasiswa_elektro, app name, app URL)
- [x] ✅ Install package dependencies:
  - [x] ✅ `laravel/sanctum` (authentication)
  - [x] ✅ `spatie/laravel-permission` (role & permission) atau manual role → implementasi manual role
  - [x] ✅ `maatwebsite/excel` → diganti `openspout/openspout` (PHP 8.5 incompatible dengan maatwebsite)
  - [x] ✅ `barryvdh/laravel-dompdf` (export PDF)
  - [x] ✅ `kreait/laravel-firebase` (FCM push notification) — implemented via custom FcmService (HTTP v1 API)
  - [ ] `l5-swagger` (API documentation) — optional
- [x] ✅ Configure `config/cors.php` (allow mobile & web origins)
- [x] ✅ Configure `config/sanctum.php` (token expiration)
- [x] ✅ Setup rate limiting di `app/Providers/AppServiceProvider.php`
- [x] ✅ Buat base API response helper (`app/Traits/ApiResponse.php`)
- [x] ✅ Buat base controller (`app/Http/Controllers/Controller.php` with ApiResponse trait)
- [x] ✅ Setup exception handler untuk API responses
- [x] ✅ Test: `php artisan serve` berjalan tanpa error

### Task 1.2: Database Migrations
- [x] ✅ Migration: `create_roles_table`
- [x] ✅ Migration: `create_prodis_table`
- [x] ✅ Migration: `create_users_table` (dengan semua kolom sesuai PRD-03)
- [x] ✅ Migration: `create_user_roles_table` (pivot)
- [x] ✅ Migration: `create_face_embeddings_table`
- [x] ✅ Migration: `create_re_enrollment_requests_table`
- [x] ✅ Migration: `create_tahun_ajarans_table`
- [x] ✅ Migration: `create_semesters_table`
- [x] ✅ Migration: `create_mata_kuliahs_table`
- [x] ✅ Migration: `create_mahasiswa_mata_kuliah_table` (pivot)
- [x] ✅ Migration: `create_geofences_table`
- [x] ✅ Migration: `create_jadwals_table`
- [x] ✅ Migration: `create_attendances_table`
- [x] ✅ Migration: `create_attendance_logs_table`
- [x] ✅ Migration: `create_alpha_accumulations_table`
- [x] ✅ Migration: `create_sp_records_table`
- [x] ✅ Migration: `create_leave_requests_table`
- [x] ✅ Migration: `create_prodi_settings_table`
- [x] ✅ Migration: `create_notifications_table`
- [x] ✅ Migration: `create_system_settings_table`
- [x] ✅ Migration: `create_audit_trails_table`
- [x] ✅ Migration: `create_parent_student_table` (relasi orang tua ↔ mahasiswa)
- [x] ✅ Run: `php artisan migrate` — semua berhasil tanpa error

### Task 1.3: Models & Relationships
- [x] ✅ Model: `Role` (hasMany users via pivot)
- [x] ✅ Model: `Prodi` (hasMany users, mataKuliahs, hasOne prodiSetting)
- [x] ✅ Model: `User` (belongsToMany roles, belongsTo prodi, hasMany embeddings, attendances, spRecords, children/parents via parent_student, dll)
- [x] ✅ Model: `FaceEmbedding` (belongsTo user, belongsTo approvedBy)
- [x] ✅ Model: `ReEnrollmentRequest` (belongsTo user, belongsTo approvedBy)
- [x] ✅ Model: `TahunAjaran` (hasMany semesters)
- [x] ✅ Model: `Semester` (belongsTo tahunAjaran, hasMany mataKuliahs)
- [x] ✅ Model: `MataKuliah` (belongsTo semester, prodi, dosen; belongsToMany mahasiswa)
- [x] ✅ Model: `Geofence` (belongsTo prodi, hasMany jadwals)
- [x] ✅ Model: `Jadwal` (belongsTo mataKuliah, geofence)
- [x] ✅ Model: `Attendance` (belongsTo user, jadwal, mataKuliah, approvedBy, overriddenBy)
- [x] ✅ Model: `AttendanceLog` (belongsTo attendance, user)
- [x] ✅ Model: `AlphaAccumulation` (belongsTo user, semester)
- [x] ✅ Model: `SpRecord` (belongsTo user, semester, generatedBy, signedKaprodiBy, signedKajurBy)
- [x] ✅ Model: `LeaveRequest` (belongsTo user, mataKuliah, approvedBy)
- [x] ✅ Model: `ProdiSetting` (belongsTo prodi)
- [x] ✅ Model: `Notification` (belongsTo user)
- [x] ✅ Model: `SystemSetting` (standalone)
- [x] ✅ Model: `AuditTrail` (belongsTo user)
- [x] ✅ Semua model: setup `$fillable`, `$casts`, `$hidden`, soft deletes (yang perlu)

### Task 1.4: Seeders (Semua sesuai PRD-03-database-design.md)
- [x] ✅ Seeder: `RoleSeeder` (8 roles: super_admin, ketua_jurusan, admin_jurusan, kaprodi, admin_prodi, dosen, mahasiswa, orang_tua)
- [x] ✅ Seeder: `ProdiSeeder` (3 prodi: TL=Teknik Listrik, TI=Teknik Informatika, TE=Teknik Elektro — semua D3, jurusan Teknik Elektro)
- [x] ✅ Seeder: `SystemSettingSeeder` (test_mode, app_name, institution_name, jurusan_name)
- [x] ✅ Seeder: `ProdiSettingSeeder` (toleransi_masuk=15, batas_terlambat=50%, SP1=16-31, SP2=32-37, SP3=38-45, DO=46, face_threshold=1.0, radius=50m)
- [x] ✅ Seeder: `UserSeeder` — 33 akun sesuai PRD-03:
  - 1 Super Admin: administrator@gmail.com
  - 1 Ketua Jurusan: ketua_jurusan@gmail.com
  - 1 Admin Jurusan: admin_jurusan@gmail.com
  - 3 Kaprodi: kaprodi_elektro@gmail.com, kaprodi_informatika@gmail.com, kaprodi_listrik@gmail.com
  - 3 Admin Prodi: admin_prodi_elektro@gmail.com, admin_prodi_informatika@gmail.com, admin_prodi_listrik@gmail.com
  - 9 Dosen (5 TI + 2 TE + 2 TL): dosen_yusril@gmail.com (NIDN 1234567890), dosen_adam@gmail.com, dosen_fitri@gmail.com, dosen_rudi@gmail.com, dosen_sari@gmail.com, dosen_wahyu@gmail.com, dosen_dian@gmail.com, dosen_joko@gmail.com, dosen_mega@gmail.com
  - 15 Mahasiswa TI (angkatan 2024, semester 4): mahasiswa_ahmad@gmail.com (NIM 2024001001) sampai mahasiswa_oki@gmail.com (NIM 2024001015), kelas A-E
  - 3 Orang Tua: orangtua_fauzi@gmail.com, orangtua_prasetyo@gmail.com, orangtua_saputra@gmail.com
  - Password semua: `12345678` (bcrypt)
  - must_change_password: true (SEMUA akun)
  - enrollment_status: belum (SEMUA mahasiswa)
- [x] ✅ Seeder: `TahunAjaranSeeder` (2025/2026 aktif)
- [x] ✅ Seeder: `SemesterSeeder` (Ganjil Juli-Des 2025 nonaktif, Genap Jan-Jun 2026 aktif)
- [x] ✅ Seeder: `MataKuliahSeeder` (TI-401 Pemrograman Mobile, 3 SKS, 5 kelas A-E dengan 3 dosen: Yusril=B, Adam=A+C, Fitri=D+E)
- [x] ✅ Seeder: `GeofenceSeeder` (7 lokasi: Lab Komputer 1-5 prodi TI, Ruang Teori 1 prodi TE, Ruang Teori 2 prodi TL — radius 50m)
- [x] ✅ Seeder: `JadwalSeeder` (5 jadwal: Senin 08:00-10:30 Lab 1&2, Selasa 13:00-15:30 Lab 3, Rabu 08:00-10:30 Lab 4, Rabu 13:00-15:30 Lab 5)
- [x] ✅ Seeder: `MahasiswaMataKuliahSeeder` (15 mahasiswa → 5 kelas: Ahmad/Budi/Citra=B, Dani/Eka/Fajar=A, Gita/Hadi/Indra=C, Jihan/Kiki/Lukman=D, Mira/Nanda/Oki=E)
- [x] ✅ Seeder: `ParentStudentSeeder` (3 relasi: Pak Fauzi→Ahmad, Bu Prasetyo→Budi, Pak Saputra→Dani — terintegrasi di UserSeeder)
- [x] ✅ Run: `php artisan migrate:fresh --seed` — semua berhasil, data sesuai PRD-03

### Task 1.5: API Response & Middleware Setup
- [x] ✅ Buat trait `ApiResponseTrait` (`app/Traits/ApiResponse.php` — success, error, paginated response format)
- [x] ✅ Buat middleware `CheckRole` (cek role user)
- [x] ✅ Buat middleware `EnsureEnrollmentApproved` (untuk endpoint attendance)
- [x] ✅ Register middleware di `bootstrap/app.php`
- [x] ✅ Setup route groups: `routes/api.php` (prefix `/api/`)
- [x] ✅ Test: hit endpoint test → response format benar

---

## PHASE 2: AUTHENTICATION & USER MANAGEMENT
**Estimasi: 1.5 minggu**

### Task 2.1: Authentication (Login/Logout)
- [x] ✅ Buat `AuthController`
- [x] ✅ Buat `LoginRequest` (validation: login, password) — validasi inline di controller
- [x] ✅ Implement `login()`: validate credentials, generate Sanctum token, return user + token
- [x] ✅ Implement `logout()`: revoke current token
- [x] ✅ Implement `me()`: return authenticated user with roles & prodi
- [x] ✅ Buat `ForgotPasswordController` (send reset link)
- [x] ✅ Buat `ResetPasswordController` (reset with token)
- [x] ✅ Buat `ChangePasswordController` (old password + new password) — di AuthController
- [x] ✅ Buat `UpdateFcmTokenController` (update FCM token) — di AuthController
- [x] ✅ Routes: POST `/auth/login`, POST `/auth/logout`, GET `/auth/me`, dll
- [x] ✅ Test: login dengan berbagai role → token valid

### Task 2.2: User Management - Super Admin
- [x] ✅ Buat `UserController` (full CRUD) — `Admin\UserController`
- [x] ✅ Buat `StoreUserRequest` (validation rules) — validasi inline di controller
- [x] ✅ Buat `UpdateUserRequest` (validation rules) — validasi inline di controller
- [x] ✅ Implement `index()`: list users with filters (role, prodi, status), pagination
- [x] ✅ Implement `store()`: create user + assign role
- [x] ✅ Implement `show()`: detail user + relationships
- [x] ✅ Implement `update()`: update user data
- [x] ✅ Implement `destroy()`: soft delete
- [x] ✅ Implement `assignRole()`: assign/change role — terintegrasi di store/update via roles array
- [x] ✅ Implement `toggleStatus()`: aktif/nonaktif — bisa via update(status)
- [x] ✅ Routes: resource routes + custom routes
- [x] ✅ Middleware: only admin

### Task 2.3: User Management - Mahasiswa
- [x] ✅ Buat `MahasiswaController` — terintegrasi di Admin\UserController (filter role=mahasiswa)
- [x] ✅ Buat `StoreMahasiswaRequest` (NIM unique, email unique, prodi required) — validasi di UserController
- [x] ✅ Buat `UpdateMahasiswaRequest` — validasi di UserController
- [x] ✅ Implement `index()`: list mahasiswa (filter prodi, kelas, angkatan, status)
- [x] ✅ Implement `store()`: create mahasiswa + auto-assign role + generate default password
- [x] ✅ Implement `show()`: detail + rekap kehadiran summary
- [x] ✅ Implement `update()`: update data mahasiswa
- [x] ✅ Implement `destroy()`: soft delete
- [x] ✅ Implement `import()`: bulk import dari Excel (using openspout)
- [x] ✅ Implement `export()`: export ke Excel (using openspout)
- [x] ✅ Routes + middleware (admin)

### Task 2.4: User Management - Dosen
- [x] ✅ Buat `DosenController` — terintegrasi di Admin\UserController (filter role=dosen)
- [x] ✅ Buat `StoreDosenRequest` (NIDN unique, email unique) — validasi di UserController
- [x] ✅ Buat `UpdateDosenRequest` — validasi di UserController
- [x] ✅ Implement CRUD (sama pattern seperti mahasiswa)
- [x] ✅ Routes + middleware (admin)

### Task 2.5: Profile Management
- [x] ✅ Buat `ProfileController`
- [x] ✅ Implement `show()`: get own profile
- [x] ✅ Implement `update()`: update own profile (nama, no_hp, alamat, dll)
- [x] ✅ Implement `uploadSignature()`: upload tanda tangan digital (Kaprodi, Kajur)
- [x] ✅ File upload handling (validate type, size, store) — uploadFoto
- [x] ✅ Routes (authenticated users)

---

## PHASE 3: ACADEMIC MODULE (CRUD)
**Estimasi: 2 minggu**

### Task 3.1: Tahun Ajaran CRUD
- [x] ✅ Buat `TahunAjaranController`
- [x] ✅ Buat `StoreTahunAjaranRequest` (kode unique, tanggal valid) — validasi inline
- [x] ✅ Buat `UpdateTahunAjaranRequest` — validasi inline
- [x] ✅ Implement CRUD (index, store, show, update, destroy)
- [x] ✅ Implement `activate()`: set aktif (nonaktifkan yang lain) — terintegrasi di store/update
- [x] ✅ Business logic: hanya 1 yang boleh aktif
- [x] ✅ Routes + middleware (admin)
- [x] ✅ Test: CRUD + aktivasi

### Task 3.2: Semester CRUD
- [x] ✅ Buat `SemesterController`
- [x] ✅ Buat `StoreSemesterRequest` (tahun_ajaran_id exists, tanggal valid) — validasi inline
- [x] ✅ Buat `UpdateSemesterRequest` — validasi inline
- [x] ✅ Implement CRUD
- [x] ✅ Implement `activate()`: set aktif — terintegrasi di store/update
- [x] ✅ Business logic: saat aktivasi, create alpha_accumulations baru untuk semua mahasiswa aktif
- [x] ✅ Routes + middleware
- [x] ✅ Test: CRUD + aktivasi

### Task 3.3: Mata Kuliah CRUD
- [x] ✅ Buat `MataKuliahController`
- [x] ✅ Buat `StoreMataKuliahRequest` (kode_mk + semester + kelas unique) — validasi inline
- [x] ✅ Buat `UpdateMataKuliahRequest` — validasi inline
- [x] ✅ Implement CRUD
- [x] ✅ Implement `assignMahasiswa()`: assign mahasiswa ke MK (bulk) — enrollMahasiswa()
- [x] ✅ Implement `removeMahasiswa()`: remove mahasiswa dari MK
- [x] ✅ Implement `getMahasiswa()`: list mahasiswa di MK — via show() with mahasiswas relation
- [x] ✅ Routes + middleware
- [x] ✅ Test: CRUD + assign mahasiswa

### Task 3.4: Geofence CRUD
- [x] ✅ Buat `GeofenceController`
- [x] ✅ Buat `StoreGeofenceRequest` (lat/lon valid, radius > 0) — validasi inline
- [x] ✅ Buat `UpdateGeofenceRequest` — validasi inline
- [x] ✅ Implement CRUD
- [x] ✅ Routes + middleware
- [x] ✅ Test: CRUD

### Task 3.5: Jadwal CRUD
- [x] ✅ Buat `JadwalController`
- [x] ✅ Buat `StoreJadwalRequest` (mata_kuliah_id, geofence_id, hari, jam valid) — validasi inline
- [x] ✅ Buat `UpdateJadwalRequest` — validasi inline
- [x] ✅ Implement CRUD
- [x] ✅ Implement validasi bentrok (dosen/ruangan/kelas di waktu sama)
- [x] ✅ Implement `getToday()`: jadwal hari ini — di Mahasiswa\JadwalController
- [x] ✅ Implement `getByDosen()`: jadwal per dosen — di Dosen\MataKuliahController
- [x] ✅ Routes + middleware
- [x] ✅ Test: CRUD + validasi bentrok

### Task 3.6: Prodi Settings CRUD
- [x] ✅ Buat `ProdiSettingController` — terintegrasi di Admin\ProdiController@updateSettings
- [x] ✅ Buat `UpdateProdiSettingRequest` (validate all setting fields) — validasi inline
- [x] ✅ Implement `show()`: get setting per prodi — via ProdiController@show
- [x] ✅ Implement `update()`: update setting per prodi — ProdiController@updateSettings
- [x] ✅ Routes + middleware (admin)
- [x] ✅ Test: get + update settings

---

## PHASE 4: ATTENDANCE SYSTEM (CORE)
**Estimasi: 2 minggu**

### Task 4.1: Enrollment Wajah
- [x] ✅ Buat `EnrollmentController` — Mahasiswa\EnrollmentController + Kaprodi\EnrollmentController
- [x] ✅ Buat `SubmitEnrollmentRequest` (embedding array, liveness_passed, device, foto file) — validasi inline
- [x] ✅ Implement `submit()`: simpan embedding + upload foto enrollment + status pending
  - Simpan foto enrollment (JPG, max 500KB) ke storage
  - Simpan path foto ke users.foto_enrollment
  - Simpan embedding ke face_embeddings table
- [x] ✅ Implement `status()`: cek status enrollment user
- [x] ✅ Implement `getMyEmbedding()`: get embedding aktif (untuk cache di mobile)
- [x] ✅ Implement `getPending()`: list enrollment pending (admin) — Kaprodi\EnrollmentController@index
- [x] ✅ Implement `approve()`: approve enrollment, update user enrollment_status
- [x] ✅ Implement `reject()`: reject + alasan
- [x] ✅ Buat `ReEnrollmentController` — Kaprodi\ReEnrollmentController
- [x] ✅ Implement request + approve/reject re-enrollment (foto enrollment diupdate saat re-enroll)
- [x] ✅ Routes + middleware
- [x] ? Test: submit → pending → approve → embedding aktif + foto tersimpan

### Task 4.2: Check-in Endpoint
- [x] ✅ Buat `AttendanceController` — Mahasiswa\AttendanceController
- [x] ✅ Buat `CheckinRequest` (validation: jadwal_id, lat, lon, face_distance, dll) — validasi inline
- [x] ✅ Implement validasi: enrollment approved, terdaftar di MK, hari sesuai, belum check-in
- [x] ✅ Implement service: `validateGeofence()` — hitung jarak Haversine, cek radius
- [x] ✅ Implement service: `validateMockLocation()` — cek flag mock location
- [x] ✅ Implement service: `determinateStatus()` — tentukan status berdasarkan waktu:
  - Dalam toleransi → HADIR
  - Terlambat (dalam batas) → HADIR_TERLAMBAT
  - Terlambat (melebihi batas) → PENDING
- [x] ✅ Implement controller `checkin()`: orchestrate semua validasi + simpan
- [x] ✅ Buat `AttendanceLogService` — catat setiap attempt ke attendance_logs
- [x] ✅ Routes: POST `/mahasiswa/attendance/check-in`
- [x] ? Test: semua case (tepat waktu, terlambat, pending, di luar geofence)

### Task 4.3: Check-out Endpoint
- [x] ✅ Buat `CheckoutRequest` (validation: attendance_id, lat, lon, face_distance, dll) — validasi inline
- [x] ✅ Implement service: `validateCheckoutTime()` — tentukan status checkout (pulang awal, dll)
- [x] ✅ Implement service: `calculateCheckoutAlpha()` — hitung alpha tambahan jika pulang awal
- [x] ✅ Implement service: `calculateEffectiveDuration()` — durasi efektif
- [x] ✅ Implement controller `checkout()`: orchestrate + update attendance record
- [x] ✅ Routes: POST `/mahasiswa/attendance/check-out`
- [x] ? Test: semua case (normal, pulang awal, terlambat checkout)

### Task 4.4: Auto-Close Scheduler
- [x] ✅ Buat command: `php artisan attendance:auto-close`
- [x] ✅ Logic: cari attendance yang checkin != null, checkout == null, jadwal sudah lewat + toleransi
- [x] ✅ Set checkout_time = jam_selesai, is_auto_closed = true
- [x] ✅ Hitung durasi efektif
- [x] ✅ Register di `routes/console.php` (setiap menit)
- [x] ✅ Test: simulasi auto-close

### Task 4.5: Alpha Penuh (Tidak Hadir) Scheduler
- [x] ✅ Buat command: `php artisan attendance:mark-absent`
- [x] ✅ Logic: di akhir hari, cari mahasiswa yang terdaftar di MK tapi tidak punya attendance record
- [x] ✅ Exclude yang punya leave_request approved
- [x] ✅ Create attendance record: status = ALPHA, alpha_menit = durasi MK
- [x] ✅ Register scheduler (setiap hari jam 22:00)
- [x] ✅ Test: simulasi mark absent

### Task 4.6: Alpha Accumulation Service
- [x] ✅ Buat `AlphaAccumulationService`
- [x] ✅ Implement `recalculate(userId, semesterId)`: hitung ulang total alpha dari semua attendance
- [x] ✅ Implement `checkSpStatus(userId, semesterId)`: evaluasi status SP berdasarkan total alpha — evaluateSpLevel()
- [x] ✅ Implement `getSpThresholds(prodiId)`: ambil threshold dari prodi_settings
- [x] ✅ Trigger recalculate setiap: checkin, checkout, approval, override
- [x] ✅ Update tabel `alpha_accumulations`
- [x] ? Test: kalkulasi benar untuk berbagai skenario

### Task 4.7: Offline Sync Endpoint
- [x] ✅ Buat `SyncOfflineRequest` (validation: array of attendance data) — validasi inline di OfflineSyncController
- [x] ✅ Implement `syncOffline()`: terima batch data, validasi timestamp masih dalam range
- [x] ✅ Business logic: reject jika timestamp > 30 menit setelah jadwal selesai
- [x] ✅ Flag: is_offline_synced = true (via attendance_logs action=offline_checkin/offline_checkout)
- [x] ✅ Routes: POST `/mahasiswa/attendance/sync-offline`
- [x] ✅ Test: sync valid + sync expired

### Task 4.8: Leave Request (Izin/Sakit)
- [x] ✅ Buat `LeaveRequestController` — Mahasiswa\LeaveRequestController + Kaprodi\LeaveRequestController
- [x] ✅ Buat `StoreLeaveRequest` (tipe, mata_kuliah_id, tanggal, file) — validasi inline
- [x] ✅ Implement `store()`: submit izin + upload file
- [x] ✅ Implement `myLeaves()`: list izin saya (mahasiswa) — index()
- [x] ✅ Implement `pending()`: list izin pending (kaprodi)
- [x] ✅ Implement `approve()`: approve + update attendance status ke IZIN/SAKIT
- [x] ✅ Implement `reject()`: reject + alasan
- [x] ✅ File upload handling (validate: jpg, png, pdf, max 5MB)
- [x] ✅ Routes + middleware
- [x] ? Test: submit → approve → alpha berubah

### Task 4.9: Dosen Approval & Override
- [x] ✅ Buat `DosenAttendanceController` — Dosen\AttendanceController
- [x] ✅ Implement `pendingApprovals()`: list pending di kelas yang diampu — index(status=pending)
- [x] ✅ Implement `approve()`: ubah status pending → hadir_terlambat, recalculate alpha
- [x] ✅ Implement `reject()`: ubah status pending → alpha penuh, recalculate alpha
- [x] ✅ Implement `override()`: manual override kehadiran (wajib alasan)
- [x] ✅ Implement `classToday()`: siapa yang sudah hadir di kelas hari ini (real-time)
- [x] ✅ Implement `classRecap()`: rekap per MK — rekap()
- [x] ✅ Audit trail untuk setiap override
- [x] ✅ Routes + middleware (dosen only)
- [x] ? Test: approve, reject, override

### Task 4.10: Attendance Query & History
- [x] ✅ Implement `today()`: jadwal + status absensi hari ini (mahasiswa)
- [x] ✅ Implement `history()`: riwayat absensi (filter semester, MK, pagination)
- [x] ✅ Implement `summary()`: summary kehadiran semester — di DashboardController
- [x] ✅ Implement `activeSchedule()`: jadwal yang sedang berlangsung saat ini — Mahasiswa\JadwalController@active
- [x] ✅ Routes (mahasiswa)
- [x] ? Test: query dengan berbagai filter

---

## PHASE 5: SP & EARLY WARNING SYSTEM
**Estimasi: 1.5 minggu**

### Task 5.1: SP Detection & Notification
- [x] ✅ Buat `SpDetectionService`
- [x] ✅ Implement: setiap kali alpha_accumulation berubah, cek apakah masuk threshold SP baru
- [x] ✅ Implement: cek apakah mendekati threshold (80% dari batas berikutnya)
- [x] ✅ Trigger notification jika status berubah atau mendekati batas
- [x] ✅ Integrate dengan AlphaAccumulationService
- [x] ? Test: simulasi mahasiswa naik dari AMAN → SP1 → SP2 → SP3 → DO

### Task 5.2: SP Record Management
- [x] ✅ Buat `SpRecordController` — Kaprodi\SpController + Kajur\SpController + Mahasiswa\SpController
- [x] ✅ Implement `dashboard()`: overview SP per prodi (count per level) — di Kaprodi\DashboardController
- [x] ✅ Implement `listMahasiswa()`: list mahasiswa + status SP (sortable, filterable) — Kaprodi\SpController@index
- [x] ✅ Implement `detail()`: detail SP mahasiswa (rincian per MK)
- [x] ✅ Implement `mySpRecords()`: SP saya (mahasiswa) — Mahasiswa\SpController@index
- [x] ✅ Routes + middleware
- [x] ? Test: query data SP

### Task 5.3: Generate Dokumen SP (PDF)
- [x] ✅ Buat `SpDocumentService`
- [x] ✅ Buat template Blade untuk PDF SP (header institusi, isi, tanda tangan) — `resources/views/pdf/surat-sp.blade.php`
- [x] ✅ Implement `generate()`:
  - Ambil data mahasiswa, total alpha, rincian per MK
  - Generate nomor surat otomatis (format: No/SP[level]/JTE/[bulan]/[tahun])
  - Create sp_record dengan status DRAFT
  - Generate PDF draft
- [x] ✅ Implement `sendToKaprodi()`: ubah status → MENUNGGU_KAPRODI, notif ke kaprodi
- [x] ✅ Routes: POST `/admin/sp-records/generate`
- [x] ✅ Test: generate PDF, cek format benar

### Task 5.4: SP Approval Flow (Tanda Tangan Digital)
- [x] ✅ Implement `signKaprodi()`: kaprodi approve + tanda tangan — Kaprodi\SpController@sign
  - Validasi: status menunggu_kaprodi
  - Ubah status → MENUNGGU_KAJUR
- [x] ✅ Implement `signKajur()`: kajur approve + tanda tangan "Diketahui" — Kajur\SpController@sign
  - Validasi: status menunggu_kajur
  - Ubah status → FINAL
- [x] ✅ Implement tempelkan tanda tangan ke PDF
- [x] ✅ Implement `downloadDocument()`: download PDF SP — Admin\SpController@download
- [x] ✅ Routes + middleware (kaprodi, kajur)
- [x] ? Test: full flow draft → kaprodi → kajur → final

### Task 5.5: SP History & Tracking
- [x] ✅ Implement list semua SP records (filter: prodi, level, status) — Kaprodi\SpController@index
- [x] ✅ Implement detail SP record (termasuk timeline approval)
- [x] ✅ Implement cancel SP (jika ada kesalahan)
- [x] ✅ Routes + middleware
- [x] ? Test: query + filter

---

## PHASE 6: NOTIFICATION & EXPORT
**Estimasi: 1 minggu**

### Task 6.1: Notification Service
- [x] ✅ Buat `NotificationService`
- [x] ✅ Implement `send()`: create notification record + push FCM (placeholder)
- [x] ✅ Implement `sendBulk()`: kirim ke multiple users
- [x] ✅ Setup Firebase Admin SDK — custom FcmService (HTTP v1 API langsung)
- [x] ✅ Implement FCM push (title, body, data payload) — FcmService with HTTP v1 API
- [x] ✅ Buat notification templates (SP warning, approval, reminder, dll) — `config/notification-templates.php`
- [x] ? Test: kirim notifikasi → cek di database + FCM

### Task 6.2: Notification Controller
- [x] ✅ Buat `NotificationController`
- [x] ✅ Implement `index()`: list notifikasi user (pagination, filter type)
- [x] ✅ Implement `unreadCount()`: jumlah belum dibaca
- [x] ✅ Implement `markRead()`: mark 1 as read
- [x] ✅ Implement `markAllRead()`: mark all as read
- [x] ✅ Routes (authenticated)
- [x] ? Test: CRUD notifikasi

### Task 6.3: Notification Triggers (SP - Berdasarkan Level)
- [x] ✅ Buat `SpDetectionService` (handle notifikasi SP per level — terintegrasi di SpDetectionService)
- [x] ✅ Trigger: Mendekati SP1 (alpha >= 80% threshold):
  - Push ke mahasiswa
- [x] ✅ Trigger: Masuk SP1:
  - Push ke mahasiswa
  - In-app ke Kaprodi
  - In-app ke Dosen Pengampu MK terkait (query dosen dari MK yang diambil mahasiswa)
- [x] ✅ Trigger: Mendekati SP2:
  - Push ke mahasiswa
  - In-app ke Kaprodi
- [x] ✅ Trigger: Masuk SP2:
  - Push ke mahasiswa
  - In-app ke Kaprodi
  - In-app ke Ketua Jurusan
- [x] ✅ Trigger: Mendekati SP3:
  - Push ke mahasiswa
  - In-app ke Kaprodi
- [x] ✅ Trigger: Masuk SP3:
  - Push ke mahasiswa
  - In-app ke Kaprodi
  - In-app ke Ketua Jurusan
  - In-app ke Admin
- [x] ✅ Trigger: Mendekati DO (URGENT):
  - Push ke mahasiswa (PERINGATAN KERAS)
  - In-app ke Kaprodi (URGENT)
  - In-app ke Ketua Jurusan (URGENT)
- [x] ✅ Trigger: Masuk DO (URGENT):
  - Push ke mahasiswa (URGENT)
  - In-app ke Kaprodi (URGENT)
  - In-app ke Ketua Jurusan (URGENT)
  - In-app ke Admin (URGENT)
- [x] ✅ Implement logic: hanya kirim notifikasi 1x per level (jangan berulang)
  - Simpan flag `notified_approaching_sp1`, `notified_sp1`, dll di alpha_accumulations
  - Cek flag sebelum kirim
- [x] ✅ Implement helper: `getRecipientsBySpLevel(level, mahasiswa)` — getApproachingRecipients() + getLevelChangeRecipients()

### Task 6.3B: Notification Triggers (Non-SP)
- [x] ✅ Trigger: Pending approval baru → in-app ke dosen pengampu
- [x] ✅ Trigger: Approval result (approve/reject) → push ke mahasiswa
- [x] ✅ Trigger: Enrollment result (approve/reject) → push ke mahasiswa
- [x] ✅ Trigger: Leave request result (approve/reject) → push ke mahasiswa
- [x] ✅ Trigger: Reminder absen (15 menit sebelum kelas) → push ke mahasiswa — SendAttendanceReminder command + scheduler
- [x] ✅ Trigger: SP document needs signature → in-app ke kaprodi/kajur — SpDocumentService.sendToKaprodi/sendToKajur
- [x] ✅ Trigger: SP document final → push ke mahasiswa (bisa download)
- [x] ✅ Register semua triggers di service/event listeners (Laravel Events + Listeners)
- [x] ? Test: setiap trigger berjalan dengan penerima yang benar

### Task 6.4: Export Service (Excel)
- [x] ✅ Buat `AttendanceExport` (OpenSpout — pengganti Maatwebsite karena PHP 8.5 incompatible)
- [x] ✅ Sheet 1: Summary (nama, NIM, total hadir, total alpha, status SP)
- [x] ✅ Sheet 2: Detail per pertemuan
- [x] ✅ Sheet 3: Rincian alpha per MK
- [x] ✅ Implement filter: semester, prodi, kelas, MK
- [x] ✅ Routes: GET `/admin/reports/export/excel`
- [x] ? Test: export → file valid

### Task 6.5: Export Service (PDF)
- [x] ✅ Buat template Blade untuk laporan PDF — `resources/views/pdf/rekap-kehadiran.blade.php`
- [x] ✅ Header institusi, tabel rekap, footer
- [x] ✅ Implement filter: semester, prodi, kelas, MK
- [x] ✅ Routes: GET `/admin/reports/export/pdf`
- [x] ? Test: export → PDF valid

### Task 6.6: Report Endpoints
- [x] ✅ Buat `ReportController` — Admin\ReportController
- [x] ✅ Implement `byMahasiswa()`: rekap per mahasiswa
- [x] ✅ Implement `byMataKuliah()`: rekap per MK (matrix view data)
- [x] ✅ Implement `byKelas()`: rekap per kelas
- [x] ✅ Implement `byProdi()`: rekap per prodi
- [x] ✅ Implement `byJurusan()`: rekap seluruh jurusan
- [x] ✅ Implement dashboard data endpoints (per role: admin, kaprodi, kajur, dosen, mahasiswa, orangtua)
- [x] ✅ Routes + middleware (sesuai role)
- [x] ✅ Test: semua report endpoint return data benar

### Task 6.7: Mode Pengujian (Testing Mode)
- [x] ✅ Buat `TestModeController` — Admin\TestModeController
- [x] ✅ Implement `toggle()`: aktifkan/nonaktifkan mode pengujian
- [x] ✅ Implement: saat mode aktif, attendance_logs dicatat dengan is_test_mode = true (via metadata)
- [x] ✅ Implement: endpoint untuk label genuine/impostor pada log — labelLog()
- [x] ✅ Routes + middleware (admin only)
- [x] ? Test: toggle mode + log tercatat dengan label

### Task 6.8: Analisis & Evaluasi Endpoints
- [x] ✅ Buat `AnalysisController` — Admin\AnalysisController
- [x] ✅ Implement `geofence()`: data evaluasi geofence (distribusi jarak, success rate)
- [x] ✅ Implement `faceVerification()`: data evaluasi face (distribusi distance, FAR, FRR)
- [x] ✅ Implement `latency()`: data latensi (per device, rata-rata, min, max, P95)
- [x] ✅ Implement `attendanceSp()`: data kehadiran & SP (distribusi, trend)
- [x] ✅ Implement `simultaneousTest()`: data uji simultan (response time per concurrent level)
- [x] ✅ Implement `conventionalComparison()`: data perbandingan (input manual + data sistem)
- [x] ✅ Implement `storeConventionalData()`: input data konvensional (POST)
- [x] ✅ Routes + middleware (admin only)
- [x] ? Test: semua endpoint return data yang benar
