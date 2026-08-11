<?php

use App\Http\Controllers\Api\Admin\AnalysisController;
use App\Http\Controllers\Api\Admin\AuditTrailController;
use App\Http\Controllers\Api\Admin\DashboardController;
use App\Http\Controllers\Api\Admin\GeofenceController;
use App\Http\Controllers\Api\Admin\JadwalController;
use App\Http\Controllers\Api\Admin\MataKuliahController;
use App\Http\Controllers\Api\Admin\ProdiController;
use App\Http\Controllers\Api\Admin\ReportController;
use App\Http\Controllers\Api\Admin\SemesterController;
use App\Http\Controllers\Api\Admin\SettingController;
use App\Http\Controllers\Api\Admin\SpController;
use App\Http\Controllers\Api\Admin\TahunAjaranController;
use App\Http\Controllers\Api\Admin\TestModeController;
use App\Http\Controllers\Api\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ForgotPasswordController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\Kaprodi\AttendanceController;
use App\Http\Controllers\Api\Kaprodi\EnrollmentController;
use App\Http\Controllers\Api\Kaprodi\LeaveRequestController;
use App\Http\Controllers\Api\Kaprodi\ReEnrollmentController;
use App\Http\Controllers\Api\Mahasiswa\AttendancePermitController;
use App\Http\Controllers\Api\Mahasiswa\OfflineSyncController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\OrangTua\ChildController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\ResetPasswordController;
use App\Http\Controllers\PrivateFileController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Prefix: /api
| Semua route di sini otomatis mendapat prefix /api
|
*/

Route::get('/health', [HealthController::class, 'health']);
Route::get('/healthz', [HealthController::class, 'readiness'])
    ->middleware(['auth:sanctum', 'user.active', 'role:super_admin']);

// Auth routes (public)
Route::prefix('auth')->middleware('throttle:login')->group(function () {
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/forgot-password', [ForgotPasswordController::class, 'sendResetLink']);
    Route::post('/reset-password', [ResetPasswordController::class, 'reset']);
});

// Protected routes
//
// M-23: limiter `api` sudah lama terdefinisi di `AppServiceProvider` tetapi
// tidak pernah dipasang, sehingga seluruh endpoint terautentikasi tanpa
// limiter eksplisit tidak berbatas. Dipasang di group ini (bukan group API
// global) agar hanya berlaku untuk request yang membawa identitas dan dapat
// di-key per user; route publik memakai `throttle:login` sendiri.
Route::middleware(['auth:sanctum', 'user.active', 'throttle:api'])->group(function () {

    Route::get('/private/enrollment-photos/{user}', [
        PrivateFileController::class,
        'enrollmentPhoto',
    ])->middleware('signed')->name('private.enrollment-photos.show');
    Route::get('/private/re-enrollment-photos/{reEnrollment}', [
        PrivateFileController::class,
        'reEnrollmentPhoto',
    ])->middleware('signed')->name('private.re-enrollment-photos.show');
    Route::get('/private/leave-documents/{leaveRequest}', [
        PrivateFileController::class,
        'leaveDocument',
    ])->middleware('signed')->name('private.leave-documents.show');

    // Auth
    Route::prefix('auth')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        // M-23: lebih ketat dari limiter API umum karena memverifikasi
        // `current_password`, sehingga merupakan permukaan brute force.
        Route::post('/change-password', [AuthController::class, 'changePassword'])
            ->middleware('throttle:auth-sensitive');
    });

    // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);
    Route::post('/profile/foto', [ProfileController::class, 'uploadFoto']);
    Route::post('/profile/signature', [ProfileController::class, 'uploadSignature']);

    // Notifications
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'index']);
        Route::get('/unread-count', [NotificationController::class, 'unreadCount']);
        Route::put('/{id}/read', [NotificationController::class, 'markAsRead']);
        Route::put('/read-all', [NotificationController::class, 'markAllAsRead']);
    });

    // FCM Token
    Route::post('/fcm-token', [AuthController::class, 'updateFcmToken']);

    // ==================== ADMIN ROUTES ====================
    Route::prefix('admin')->middleware('role:super_admin,admin_jurusan,admin_prodi')->group(function () {
        // User management
        Route::post('/users/import', [UserController::class, 'import']);
        Route::get('/users/export', [UserController::class, 'export']);
        Route::post('/users/{id}/reset-password', [UserController::class, 'resetPassword']);
        Route::put('/users/{id}/toggle-status', [UserController::class, 'toggleStatus']);
        // Catatan: nama route diberi prefix `api.admin.*` agar TIDAK bentrok dengan
        // route web dashboard (users.index, prodi.index, dst.). Aplikasi mobile
        // memakai URL literal, bukan nama route, jadi penamaan ini aman.
        Route::apiResource('users', UserController::class)->names('api.admin.users');

        // Prodi
        Route::put('/prodi/{id}/settings', [ProdiController::class, 'updateSettings']);
        Route::apiResource('prodi', ProdiController::class)->names('api.admin.prodi');

        // Tahun Ajaran & Semester
        Route::apiResource('tahun-ajaran', TahunAjaranController::class)->names('api.admin.tahun-ajaran');
        Route::apiResource('semester', SemesterController::class)->names('api.admin.semester');

        // Mata Kuliah
        Route::post('/mata-kuliah/{id}/enroll', [MataKuliahController::class, 'enrollMahasiswa']);
        Route::delete('/mata-kuliah/{id}/remove-mahasiswa', [MataKuliahController::class, 'removeMahasiswa']);
        Route::apiResource('mata-kuliah', MataKuliahController::class)->names('api.admin.mata-kuliah');

        // Geofence
        Route::apiResource('geofence', GeofenceController::class)->names('api.admin.geofence');

        // Jadwal
        Route::apiResource('jadwal', JadwalController::class)->names('api.admin.jadwal');

        // System Settings
        Route::get('/settings', [SettingController::class, 'index']);
        Route::put('/settings', [SettingController::class, 'update']);

        // Dashboard
        Route::get('/dashboard', [DashboardController::class, 'index']);

        // SP Management
        Route::get('/sp-records', [SpController::class, 'index']);
        Route::post('/sp-records/generate', [SpController::class, 'generate']);
        Route::post('/sp-records/{id}/send-to-kaprodi', [SpController::class, 'sendToKaprodi']);
        Route::get('/sp-records/{id}/download', [SpController::class, 'download']);
        Route::get('/sp-records/{id}', [SpController::class, 'show']);
        Route::post('/sp-records/{id}/cancel', [SpController::class, 'cancel']);

        // Reports
        Route::prefix('reports')->group(function () {
            Route::get('/by-mahasiswa', [ReportController::class, 'byMahasiswa']);
            Route::get('/by-mata-kuliah', [ReportController::class, 'byMataKuliah']);
            Route::get('/by-kelas', [ReportController::class, 'byKelas']);
            Route::get('/by-prodi', [ReportController::class, 'byProdi']);
            Route::get('/by-jurusan', [ReportController::class, 'byJurusan']);
            Route::get('/export/pdf', [ReportController::class, 'exportPdf'])->middleware('throttle:export');
            Route::get('/export/excel', [ReportController::class, 'exportExcel'])->middleware('throttle:export');
        });

        // Audit Trail
        Route::get('/audit-trail', [AuditTrailController::class, 'index']);

        // Test Mode
        Route::prefix('test-mode')->group(function () {
            Route::get('/status', [TestModeController::class, 'status']);
            Route::post('/toggle', [TestModeController::class, 'toggle']);
            Route::get('/logs', [TestModeController::class, 'logs']);
            Route::put('/logs/{id}/label', [TestModeController::class, 'labelLog']);
            Route::get('/summary', [TestModeController::class, 'summary']);
        });

        // Analysis & Evaluation
        Route::prefix('analysis')->group(function () {
            Route::get('/geofence', [AnalysisController::class, 'geofence']);
            Route::get('/face-verification', [AnalysisController::class, 'faceVerification']);
            Route::get('/latency', [AnalysisController::class, 'latency']);
            Route::get('/attendance-sp', [AnalysisController::class, 'attendanceSp']);
            Route::get('/simultaneous-test', [AnalysisController::class, 'simultaneousTest']);
            Route::get('/conventional-comparison', [AnalysisController::class, 'conventionalComparison']);
            Route::post('/conventional-data', [AnalysisController::class, 'storeConventionalData']);
        });
    });

    // ==================== KAPRODI ROUTES ====================
    Route::prefix('kaprodi')->middleware('role:kaprodi,super_admin')->group(function () {
        // Enrollment approval
        Route::get('/enrollments', [EnrollmentController::class, 'index']);
        Route::put('/enrollments/{id}/approve', [EnrollmentController::class, 'approve'])->middleware('biometric.trusted');
        Route::put('/enrollments/{id}/reject', [EnrollmentController::class, 'reject']);

        // Re-enrollment
        Route::get('/re-enrollments', [ReEnrollmentController::class, 'index']);
        Route::put('/re-enrollments/{id}/approve', [ReEnrollmentController::class, 'approve'])->middleware('biometric.trusted');
        Route::put('/re-enrollments/{id}/reject', [ReEnrollmentController::class, 'reject']);

        // SP Management
        Route::get('/sp-records', [App\Http\Controllers\Api\Kaprodi\SpController::class, 'index']);
        Route::put('/sp-records/{id}/sign', [App\Http\Controllers\Api\Kaprodi\SpController::class, 'sign']);
        Route::get('/sp-records/{id}', [App\Http\Controllers\Api\Kaprodi\SpController::class, 'show']);
        Route::post('/sp-records/{id}/cancel', [App\Http\Controllers\Api\Kaprodi\SpController::class, 'cancel']);

        // Leave Requests
        Route::get('/leave-requests', [LeaveRequestController::class, 'index']);
        Route::put('/leave-requests/{id}/approve', [LeaveRequestController::class, 'approve']);
        Route::put('/leave-requests/{id}/reject', [LeaveRequestController::class, 'reject']);

        // Dashboard
        Route::get('/dashboard', [App\Http\Controllers\Api\Kaprodi\DashboardController::class, 'index']);

        // Attendance override
        Route::put('/attendance/{id}/override', [AttendanceController::class, 'override']);
    });

    // ==================== DOSEN ROUTES ====================
    Route::prefix('dosen')->middleware('role:dosen,super_admin')->group(function () {
        // Mata kuliah yang diampu
        Route::get('/mata-kuliah', [App\Http\Controllers\Api\Dosen\MataKuliahController::class, 'index']);
        Route::get('/mata-kuliah/{id}/mahasiswa', [App\Http\Controllers\Api\Dosen\MataKuliahController::class, 'mahasiswa']);

        // Attendance management
        Route::get('/attendance', [App\Http\Controllers\Api\Dosen\AttendanceController::class, 'index']);
        Route::get('/attendance/class-today', [App\Http\Controllers\Api\Dosen\AttendanceController::class, 'classToday']);
        Route::get('/attendance/rekap/{mataKuliahId}', [App\Http\Controllers\Api\Dosen\AttendanceController::class, 'rekap']);
        Route::put('/attendance/{id}/approve', [App\Http\Controllers\Api\Dosen\AttendanceController::class, 'approve']);
        Route::put('/attendance/{id}/reject', [App\Http\Controllers\Api\Dosen\AttendanceController::class, 'reject']);
        Route::put('/attendance/{id}/override', [App\Http\Controllers\Api\Dosen\AttendanceController::class, 'override']);

        // Dashboard
        Route::get('/dashboard', [App\Http\Controllers\Api\Dosen\DashboardController::class, 'index']);
    });

    // ==================== MAHASISWA ROUTES ====================
    Route::prefix('mahasiswa')->middleware('role:mahasiswa')->group(function () {
        // Face enrollment
        Route::post('/enrollment', [App\Http\Controllers\Api\Mahasiswa\EnrollmentController::class, 'store'])->middleware('throttle:biometric-probe', 'biometric.trusted');
        Route::post('/enrollment/check-duplicate', [App\Http\Controllers\Api\Mahasiswa\EnrollmentController::class, 'checkDuplicate'])->middleware('throttle:biometric-probe', 'biometric.trusted');
        Route::get('/enrollment/status', [App\Http\Controllers\Api\Mahasiswa\EnrollmentController::class, 'status']);

        Route::post('/re-enrollment', [App\Http\Controllers\Api\Mahasiswa\EnrollmentController::class, 'requestReEnrollment'])->middleware('biometric.trusted');
        Route::get('/enrollment/embedding', [App\Http\Controllers\Api\Mahasiswa\EnrollmentController::class, 'getMyEmbedding'])->middleware('enrollment.approved', 'biometric.trusted');

        // Attendance
        Route::post('/attendance/permits', [AttendancePermitController::class, 'store'])->middleware('throttle:attendance', 'enrollment.approved', 'biometric.trusted');
        Route::post('/attendance/check-in', [App\Http\Controllers\Api\Mahasiswa\AttendanceController::class, 'checkIn'])->middleware('throttle:attendance', 'enrollment.approved', 'biometric.trusted');
        Route::post('/attendance/check-out', [App\Http\Controllers\Api\Mahasiswa\AttendanceController::class, 'checkOut'])->middleware('throttle:attendance', 'enrollment.approved', 'biometric.trusted');
        Route::get('/attendance/history', [App\Http\Controllers\Api\Mahasiswa\AttendanceController::class, 'history']);
        Route::get('/attendance/today', [App\Http\Controllers\Api\Mahasiswa\AttendanceController::class, 'today']);
        Route::post('/attendance/sync-offline', [OfflineSyncController::class, 'sync'])->middleware('throttle:attendance', 'enrollment.approved', 'biometric.trusted');

        // Jadwal
        Route::get('/jadwal', [App\Http\Controllers\Api\Mahasiswa\JadwalController::class, 'index']);
        Route::get('/jadwal/today', [App\Http\Controllers\Api\Mahasiswa\JadwalController::class, 'today']);
        Route::get('/jadwal/active', [App\Http\Controllers\Api\Mahasiswa\JadwalController::class, 'active']);

        // Leave request
        Route::get('/leave-requests', [App\Http\Controllers\Api\Mahasiswa\LeaveRequestController::class, 'index']);
        Route::post('/leave-requests', [App\Http\Controllers\Api\Mahasiswa\LeaveRequestController::class, 'store']);

        // SP
        Route::get('/sp-records', [App\Http\Controllers\Api\Mahasiswa\SpController::class, 'index']);
        Route::get('/sp-records/{id}', [App\Http\Controllers\Api\Mahasiswa\SpController::class, 'show']);

        // Dashboard
        Route::get('/dashboard', [App\Http\Controllers\Api\Mahasiswa\DashboardController::class, 'index']);
    });

    // ==================== ORANG TUA ROUTES ====================
    Route::prefix('orang-tua')->middleware('role:orang_tua')->group(function () {
        // Children
        Route::get('/children', [ChildController::class, 'index']);
        Route::get('/children/{id}/attendance', [ChildController::class, 'attendance']);
        Route::get('/children/{id}/sp-records', [ChildController::class, 'spRecords']);

        // Dashboard
        Route::get('/dashboard', [App\Http\Controllers\Api\OrangTua\DashboardController::class, 'index']);
    });

    // ==================== KAJUR ROUTES ====================
    Route::prefix('kajur')->middleware('role:ketua_jurusan,super_admin')->group(function () {
        // SP signing
        Route::get('/sp-records', [App\Http\Controllers\Api\Kajur\SpController::class, 'index']);
        Route::put('/sp-records/{id}/sign', [App\Http\Controllers\Api\Kajur\SpController::class, 'sign']);
        Route::get('/sp-records/{id}', [App\Http\Controllers\Api\Kajur\SpController::class, 'show']);

        // Dashboard
        Route::get('/dashboard', [App\Http\Controllers\Api\Kajur\DashboardController::class, 'index']);
    });
});
