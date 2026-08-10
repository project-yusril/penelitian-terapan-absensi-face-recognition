<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\MataKuliah;
use App\Models\Semester;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request, AuthorizationService $authorization): JsonResponse
    {
        $actor = $request->user();
        $semesterAktif = Semester::where('status', 'aktif')->first();

        // Statistik user
        $totalMahasiswa = $authorization->scopeUsers(User::query(), $actor)
            ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('status', 'aktif')->count();
        $totalDosen = $authorization->scopeUsers(User::query(), $actor)
            ->whereHas('roles', fn ($q) => $q->where('name', 'dosen'))
            ->where('status', 'aktif')->count();

        // Statistik akademik
        $totalMataKuliah = $semesterAktif
            ? $authorization->scopeMataKuliahs(MataKuliah::query(), $actor)
                ->where('semester_id', $semesterAktif->id)->where('status', 'aktif')->count()
            : 0;

        // Statistik kehadiran hari ini
        $todayStats = $authorization->scopeAttendances(Attendance::query(), $actor)
            ->whereDate('tanggal', today())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status = 'hadir_terlambat' THEN 1 ELSE 0 END) as terlambat,
                SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha,
                SUM(CASE WHEN status = 'izin' THEN 1 ELSE 0 END) as izin,
                SUM(CASE WHEN status = 'sakit' THEN 1 ELSE 0 END) as sakit
            ")
            ->first();

        // Enrollment pending
        $enrollmentPending = $authorization->scopeUsers(User::query(), $actor)
            ->where('enrollment_status', 'pending')->count();

        return $this->success([
            'users' => [
                'total_mahasiswa' => $totalMahasiswa,
                'total_dosen' => $totalDosen,
            ],
            'academic' => [
                'semester_aktif' => $semesterAktif?->only(['id', 'nama', 'kode', 'tanggal_mulai', 'tanggal_selesai']),
                'total_mata_kuliah' => $totalMataKuliah,
            ],
            'attendance_today' => [
                'total' => $todayStats->total ?? 0,
                'hadir' => $todayStats->hadir ?? 0,
                'terlambat' => $todayStats->terlambat ?? 0,
                'alpha' => $todayStats->alpha ?? 0,
                'izin' => $todayStats->izin ?? 0,
                'sakit' => $todayStats->sakit ?? 0,
            ],
            'enrollment_pending' => $enrollmentPending,
        ]);
    }
}
