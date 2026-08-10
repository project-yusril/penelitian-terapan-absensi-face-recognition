<?php

namespace App\Http\Controllers\Api\Kajur;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Semester;
use App\Models\SpRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $semesterAktif = Semester::where('status', 'aktif')->first();

        // Total mahasiswa seluruh jurusan
        $totalMahasiswa = User::whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('status', 'aktif')
            ->count();

        // SP menunggu tanda tangan
        $spMenunggu = SpRecord::where('status', 'menunggu_kajur')->count();

        // SP stats per prodi
        $spPerProdi = SpRecord::where('semester_id', $semesterAktif?->id)
            ->join('users', 'sp_records.user_id', '=', 'users.id')
            ->join('prodis', 'users.prodi_id', '=', 'prodis.id')
            ->selectRaw("
                prodis.nama as prodi_nama,
                SUM(CASE WHEN sp_records.sp_level = 'sp1' THEN 1 ELSE 0 END) as sp1,
                SUM(CASE WHEN sp_records.sp_level = 'sp2' THEN 1 ELSE 0 END) as sp2,
                SUM(CASE WHEN sp_records.sp_level = 'sp3' THEN 1 ELSE 0 END) as sp3
            ")
            ->groupBy('prodis.nama')
            ->get();

        // Kehadiran hari ini seluruh jurusan
        $todayStats = Attendance::whereDate('tanggal', today())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha
            ")
            ->first();

        return $this->success([
            'total_mahasiswa' => $totalMahasiswa,
            'sp_menunggu_tanda_tangan' => $spMenunggu,
            'sp_per_prodi' => $spPerProdi,
            'attendance_today' => [
                'total' => $todayStats->total ?? 0,
                'hadir' => $todayStats->hadir ?? 0,
                'alpha' => $todayStats->alpha ?? 0,
            ],
        ]);
    }
}
