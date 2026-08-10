<?php

use App\Http\Controllers\PrivateFileController;
use App\Http\Controllers\Web\AnalysisController;
use App\Http\Controllers\Web\ApprovalController;
use App\Http\Controllers\Web\AttendanceController;
use App\Http\Controllers\Web\AuditTrailController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\DosenAttendanceController;
use App\Http\Controllers\Web\GeofenceController;
use App\Http\Controllers\Web\JadwalController;
use App\Http\Controllers\Web\MaintenanceController;
use App\Http\Controllers\Web\MataKuliahController;
use App\Http\Controllers\Web\NotificationController;
use App\Http\Controllers\Web\ProdiController;
use App\Http\Controllers\Web\ProfileController;
use App\Http\Controllers\Web\PushSubscriptionController;
use App\Http\Controllers\Web\ReportController;
use App\Http\Controllers\Web\SemesterController;
use App\Http\Controllers\Web\SettingController;
use App\Http\Controllers\Web\SpController;
use App\Http\Controllers\Web\TahunAjaranController;
use App\Http\Controllers\Web\TestModeController;
use App\Http\Controllers\Web\TwoFactorController;
use App\Http\Controllers\Web\UserController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes — Dashboard Admin (Laravel + Inertia + Vue + Tailwind)
|--------------------------------------------------------------------------
| Dashboard admin menyatu di dalam aplikasi backend ini (server-side
| rendering via Inertia). Aplikasi mobile tetap memakai /api.
*/

Route::get('/', fn () => redirect()->route('login'));

// ---- Guest (belum login) ----
Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware('throttle:login')->name('login.store');
});

// ---- Authenticated dashboard ----
Route::middleware(['auth', 'user.active', 'web.role:super_admin,ketua_jurusan,admin_jurusan,kaprodi,admin_prodi,dosen'])
    ->group(function () {
        Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

        Route::get('/private/enrollment-photos/{user}', [PrivateFileController::class, 'enrollmentPhoto'])
            ->middleware('signed')->name('web.private.enrollment-photos.show');
        Route::get('/private/re-enrollment-photos/{reEnrollment}', [PrivateFileController::class, 'reEnrollmentPhoto'])
            ->middleware('signed')->name('web.private.re-enrollment-photos.show');
        Route::get('/private/leave-documents/{leaveRequest}', [PrivateFileController::class, 'leaveDocument'])
            ->middleware('signed')->name('web.private.leave-documents.show');

        Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

        // Profil Saya — semua role dashboard.
        Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

        // Two-Factor Authentication (opsional, semua role dashboard).
        Route::get('/profile/2fa', [TwoFactorController::class, 'index'])->name('profile.2fa');
        Route::post('/profile/2fa/setup', [TwoFactorController::class, 'setup'])->name('profile.2fa.setup');
        Route::post('/profile/2fa/confirm', [TwoFactorController::class, 'confirm'])->name('profile.2fa.confirm');
        Route::post('/profile/2fa/disable', [TwoFactorController::class, 'disable'])->name('profile.2fa.disable');

        // Challenge OTP saat login (di-bypass middleware 2fa via nama route).
        Route::get('/two-factor/challenge', [TwoFactorController::class, 'challenge'])->name('two-factor.challenge');
        Route::post('/two-factor/verify', [TwoFactorController::class, 'verify'])
            ->middleware('throttle:5,1')->name('two-factor.verify');

        // Master data — hanya admin/manajemen (bukan dosen).

        Route::middleware('web.role:super_admin,admin_jurusan,admin_prodi')
            ->group(function () {
                Route::get('/users', [UserController::class, 'index'])->name('users.index');
                Route::post('/users', [UserController::class, 'store'])->name('users.store');
                Route::post('/users/bulk-action', [UserController::class, 'bulkAction'])->name('users.bulk-action');
                Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
                Route::delete('/users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

                // Import/Export mahasiswa (Excel)
                Route::get('/users/mahasiswa/export', [UserController::class, 'exportMahasiswa'])->name('users.mahasiswa.export');
                Route::get('/users/mahasiswa/template', [UserController::class, 'templateMahasiswa'])->name('users.mahasiswa.template');
                Route::post('/users/mahasiswa/import', [UserController::class, 'importMahasiswa'])->name('users.mahasiswa.import');

                Route::get('/prodi', [ProdiController::class, 'index'])->name('prodi.index');
                Route::post('/prodi', [ProdiController::class, 'store'])->name('prodi.store');
                Route::put('/prodi/{prodi}', [ProdiController::class, 'update'])->name('prodi.update');
                Route::delete('/prodi/{prodi}', [ProdiController::class, 'destroy'])->name('prodi.destroy');

                Route::get('/mata-kuliah', [MataKuliahController::class, 'index'])->name('mata-kuliah.index');
                Route::post('/mata-kuliah', [MataKuliahController::class, 'store'])->name('mata-kuliah.store');
                Route::put('/mata-kuliah/{matkul}', [MataKuliahController::class, 'update'])->name('mata-kuliah.update');
                Route::delete('/mata-kuliah/{matkul}', [MataKuliahController::class, 'destroy'])->name('mata-kuliah.destroy');
                // Kelola peserta MK (enroll/unenroll mahasiswa)
                Route::get('/mata-kuliah/{matkul}/peserta', [MataKuliahController::class, 'mahasiswa'])->name('mata-kuliah.peserta');
                Route::post('/mata-kuliah/{matkul}/enroll', [MataKuliahController::class, 'enroll'])->name('mata-kuliah.enroll');
                Route::delete('/mata-kuliah/{matkul}/peserta/{mahasiswa}', [MataKuliahController::class, 'unenroll'])->name('mata-kuliah.unenroll');

                Route::get('/jadwal', [JadwalController::class, 'index'])->name('jadwal.index');

                Route::post('/jadwal', [JadwalController::class, 'store'])->name('jadwal.store');
                Route::put('/jadwal/{jadwal}', [JadwalController::class, 'update'])->name('jadwal.update');
                Route::delete('/jadwal/{jadwal}', [JadwalController::class, 'destroy'])->name('jadwal.destroy');

                Route::get('/tahun-ajaran', [TahunAjaranController::class, 'index'])->name('tahun-ajaran.index');
                Route::post('/tahun-ajaran', [TahunAjaranController::class, 'store'])->name('tahun-ajaran.store');
                Route::put('/tahun-ajaran/{tahunAjaran}', [TahunAjaranController::class, 'update'])->name('tahun-ajaran.update');
                Route::delete('/tahun-ajaran/{tahunAjaran}', [TahunAjaranController::class, 'destroy'])->name('tahun-ajaran.destroy');

                Route::get('/semester', [SemesterController::class, 'index'])->name('semester.index');
                Route::post('/semester', [SemesterController::class, 'store'])->name('semester.store');
                Route::put('/semester/{semester}', [SemesterController::class, 'update'])->name('semester.update');
                Route::delete('/semester/{semester}', [SemesterController::class, 'destroy'])->name('semester.destroy');

                Route::get('/geofence', [GeofenceController::class, 'index'])->name('geofence.index');
                Route::post('/geofence', [GeofenceController::class, 'store'])->name('geofence.store');
                Route::put('/geofence/{geofence}', [GeofenceController::class, 'update'])->name('geofence.update');
                Route::delete('/geofence/{geofence}', [GeofenceController::class, 'destroy'])->name('geofence.destroy');
            });

        // Persetujuan — Kaprodi (dan super admin).
        Route::middleware('web.role:super_admin,kaprodi')
            ->group(function () {
                Route::get('/enrollments', [ApprovalController::class, 'enrollments'])->name('enrollments.index');
                Route::put('/enrollments/{user}/approve', [ApprovalController::class, 'approveEnrollment'])->name('enrollments.approve');
                Route::put('/enrollments/{user}/reject', [ApprovalController::class, 'rejectEnrollment'])->name('enrollments.reject');

                Route::get('/re-enrollments', [ApprovalController::class, 'reEnrollments'])->name('re-enrollments.index');
                Route::put('/re-enrollments/{reEnrollment}/approve', [ApprovalController::class, 'approveReEnrollment'])->name('re-enrollments.approve');
                Route::put('/re-enrollments/{reEnrollment}/reject', [ApprovalController::class, 'rejectReEnrollment'])->name('re-enrollments.reject');

                Route::get('/leave-requests', [ApprovalController::class, 'leaveRequests'])->name('leave-requests.index');
                Route::put('/leave-requests/{leaveRequest}/approve', [ApprovalController::class, 'approveLeave'])->name('leave-requests.approve');
                Route::put('/leave-requests/{leaveRequest}/reject', [ApprovalController::class, 'rejectLeave'])->name('leave-requests.reject');
            });

        // Konfigurasi sistem — super admin, kaprodi, admin prodi.
        Route::middleware('web.role:super_admin,kaprodi,admin_prodi')
            ->group(function () {
                Route::get('/settings', [SettingController::class, 'index'])->name('settings.index');
                Route::put('/settings/system', [SettingController::class, 'updateSystem'])->name('settings.system.update');
                Route::put('/settings/{prodi}', [SettingController::class, 'update'])->name('settings.update');

            });

        // Surat Peringatan — admin prodi, kaprodi, ketua jurusan, super admin.
        Route::middleware('web.role:super_admin,ketua_jurusan,admin_jurusan,kaprodi,admin_prodi')
            ->group(function () {
                Route::get('/sp', [SpController::class, 'index'])->name('sp.index');
                Route::post('/sp/generate', [SpController::class, 'generate'])->name('sp.generate');
                Route::post('/sp/{sp}/send', [SpController::class, 'sendToKaprodi'])->name('sp.send');
                Route::post('/sp/{sp}/sign-kaprodi', [SpController::class, 'signKaprodi'])->name('sp.sign-kaprodi');
                Route::post('/sp/{sp}/sign-kajur', [SpController::class, 'signKajur'])->name('sp.sign-kajur');
                Route::post('/sp/{sp}/cancel', [SpController::class, 'cancel'])->name('sp.cancel');
                Route::get('/sp/{sp}/download', [SpController::class, 'download'])->name('sp.download');
            });

        // Modul Dosen — approval/override kehadiran kelas yang diampu.
        Route::middleware('web.role:super_admin,dosen')
            ->prefix('dosen')->name('dosen.')
            ->group(function () {
                Route::get('/attendance', [DosenAttendanceController::class, 'index'])->name('attendance.index');
                Route::get('/rekap', [DosenAttendanceController::class, 'rekap'])->name('rekap');
                Route::put('/attendance/{attendance}/approve', [DosenAttendanceController::class, 'approve'])->name('attendance.approve');

                Route::put('/attendance/{attendance}/reject', [DosenAttendanceController::class, 'reject'])->name('attendance.reject');
                Route::put('/attendance/{attendance}/override', [DosenAttendanceController::class, 'override'])->name('attendance.override');
            });

        // Laporan & rekap — admin/manajemen.
        Route::middleware('web.role:super_admin,ketua_jurusan,admin_jurusan,kaprodi,admin_prodi')
            ->group(function () {
                Route::get('/reports', [ReportController::class, 'index'])->name('reports.index');
                Route::get('/reports/export/excel', [ReportController::class, 'exportExcel'])->name('reports.export.excel');
                Route::get('/reports/export/pdf', [ReportController::class, 'exportPdf'])->name('reports.export.pdf');
            });

        // Audit trail — super admin & admin jurusan.
        Route::middleware('web.role:super_admin,admin_jurusan')
            ->group(function () {
                Route::get('/audit-trail', [AuditTrailController::class, 'index'])->name('audit-trail.index');
                Route::get('/audit-trail/export', [AuditTrailController::class, 'export'])->name('audit-trail.export');

            });

        // Mode Pengujian & Analisis FAR/FRR — super admin (penelitian).
        Route::middleware('web.role:super_admin')
            ->group(function () {
                Route::get('/test-mode', [TestModeController::class, 'index'])->name('test-mode.index');
                Route::put('/test-mode/toggle', [TestModeController::class, 'toggle'])->name('test-mode.toggle');
                Route::put('/test-mode/logs/{log}/label', [TestModeController::class, 'labelLog'])->name('test-mode.label');

                Route::get('/analysis', [AnalysisController::class, 'index'])->name('analysis.index');

                // Maintenance mode toggle (super admin only).
                Route::post('/maintenance/down', [MaintenanceController::class, 'down'])->name('maintenance.down');
                Route::post('/maintenance/up', [MaintenanceController::class, 'up'])->name('maintenance.up');
            });

        // Notifikasi in-app — semua role dashboard.

        Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
        Route::put('/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('notifications.read-all');
        Route::put('/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('notifications.read');

        // Web Push (langganan browser) — semua role dashboard.
        Route::post('/push-subscriptions', [PushSubscriptionController::class, 'store'])->name('push-subscriptions.store');
        Route::delete('/push-subscriptions', [PushSubscriptionController::class, 'destroy'])->name('push-subscriptions.destroy');
        Route::post('/push-subscriptions/test', [PushSubscriptionController::class, 'test'])->name('push-subscriptions.test');

        // Monitoring kehadiran — semua role dashboard boleh lihat.
        Route::get('/attendance', [AttendanceController::class, 'index'])->name('attendance.index');
    });
