<?php

namespace App\Http\Controllers\Api\Kaprodi;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\LeaveRequest;
use App\Models\MataKuliah;
use App\Models\Semester;
use App\Models\SpRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $prodiId = $user->prodi_id;
        $semesterAktif = Semester::where('status', 'aktif')->first();

        // Total mahasiswa di prodi
        $totalMahasiswa = User::whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('prodi_id', $prodiId)
            ->where('status', 'aktif')
            ->count();

        // Enrollment pending
        $enrollmentPending = User::where('prodi_id', $prodiId)
            ->where('enrollment_status', 'pending')
            ->count();

        // Leave requests pending
        $leavePending = LeaveRequest::where('status', 'pending')
            ->whereHas('user', fn ($q) => $q->where('prodi_id', $prodiId))
            ->count();

        // SP records
        $spStats = SpRecord::whereHas('user', fn ($q) => $q->where('prodi_id', $prodiId))
            ->where('semester_id', $semesterAktif?->id)
            ->selectRaw("
                SUM(CASE WHEN sp_level = 'sp1' THEN 1 ELSE 0 END) as sp1,
                SUM(CASE WHEN sp_level = 'sp2' THEN 1 ELSE 0 END) as sp2,
                SUM(CASE WHEN sp_level = 'sp3' THEN 1 ELSE 0 END) as sp3
            ")
            ->first();

        // Kehadiran hari ini
        $mkIds = MataKuliah::where('prodi_id', $prodiId)
            ->where('semester_id', $semesterAktif?->id)
            ->pluck('id');

        $todayStats = Attendance::whereIn('mata_kuliah_id', $mkIds)
            ->whereDate('tanggal', today())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        return $this->success([
            'total_mahasiswa' => $totalMahasiswa,
            'enrollment_pending' => $enrollmentPending,
            'leave_pending' => $leavePending,
            'sp_stats' => [
                'sp1' => $spStats->sp1 ?? 0,
                'sp2' => $spStats->sp2 ?? 0,
                'sp3' => $spStats->sp3 ?? 0,
            ],
            'attendance_today' => [
                'total' => $todayStats->total ?? 0,
                'hadir' => $todayStats->hadir ?? 0,
                'alpha' => $todayStats->alpha ?? 0,
                'pending' => $todayStats->pending ?? 0,
            ],
        ]);
    }
}
