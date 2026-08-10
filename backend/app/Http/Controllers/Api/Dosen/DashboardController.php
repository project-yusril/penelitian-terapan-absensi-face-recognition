<?php

namespace App\Http\Controllers\Api\Dosen;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $semesterAktif = Semester::where('status', 'aktif')->first();

        // Mata kuliah yang diampu
        $mataKuliahs = MataKuliah::where('dosen_id', $user->id)
            ->where('semester_id', $semesterAktif?->id)
            ->where('status', 'aktif')
            ->withCount('mahasiswas')
            ->get();

        $mkIds = $mataKuliahs->pluck('id');

        // Kehadiran hari ini
        $todayStats = Attendance::whereIn('mata_kuliah_id', $mkIds)
            ->whereDate('tanggal', today())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status = 'hadir_terlambat' THEN 1 ELSE 0 END) as terlambat,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        // Pending approvals
        $pendingCount = Attendance::whereIn('mata_kuliah_id', $mkIds)
            ->where('status', 'pending')
            ->count();

        // Jadwal hari ini
        $hariIni = Carbon::now()->locale('id')->isoFormat('dddd');
        $jadwalHariIni = Jadwal::with(['mataKuliah', 'geofence'])
            ->whereIn('mata_kuliah_id', $mkIds)
            ->where('hari', $hariIni)
            ->where('status', 'aktif')
            ->orderBy('jam_mulai')
            ->get();

        return $this->success([
            'mata_kuliah' => $mataKuliahs,
            'jadwal_hari_ini' => $jadwalHariIni,
            'attendance_today' => [
                'total' => $todayStats->total ?? 0,
                'hadir' => $todayStats->hadir ?? 0,
                'terlambat' => $todayStats->terlambat ?? 0,
                'pending' => $todayStats->pending ?? 0,
            ],
            'pending_approvals' => $pendingCount,
        ]);
    }
}
