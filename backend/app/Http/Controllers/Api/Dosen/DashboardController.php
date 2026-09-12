<?php

namespace App\Http\Controllers\Api\Dosen;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\Jadwal;
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

        // RENCANA 2: mata kuliah diampu = jadwal dengan dosen_id = dia.
        $query = Jadwal::with(['mataKuliah', 'kelas:id,tingkat,nama', 'geofence'])
            ->where('dosen_id', $user->id)
            ->where('status', 'aktif');

        if ($semesterAktif) {
            $query->whereHas('mataKuliah', fn ($m) => $m->where('semester_id', $semesterAktif->id));
        }

        $jadwalDiampu = $query->orderBy('jam_mulai')->get();

        // Ringkasan per MK: MK + daftar kelas + jumlah peserta (dari kelas).
        $mataKuliahs = $jadwalDiampu->groupBy('mata_kuliah_id')->map(function ($items) {
            $mk = $items->first()->mataKuliah;
            $kelasIds = $items->pluck('kelas_id')->filter()->unique()->values();

            return [
                'id' => $mk->id,
                'kode_mk' => $mk->kode_mk,
                'nama' => $mk->nama,
                'sks' => $mk->sks,
                'kelas' => $items->map(fn ($j) => $j->kelas ? $j->kelas->tingkat.$j->kelas->nama : null)->filter()->values(),
                'mahasiswas_count' => $kelasIds->isEmpty()
                    ? 0
                    : \App\Models\User::whereHas('mahasiswaKelas', fn ($q) => $q->whereIn('kelas_id', $kelasIds))->count(),
            ];
        })->values();

        // Kehadiran hari ini (jadwal diampu)
        $jadwalIds = $jadwalDiampu->pluck('id');
        $todayStats = Attendance::whereIn('jadwal_id', $jadwalIds)
            ->whereDate('tanggal', today())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status = 'hadir_terlambat' THEN 1 ELSE 0 END) as terlambat,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        // Pending approvals
        $pendingCount = Attendance::whereIn('jadwal_id', $jadwalIds)
            ->where('status', 'pending')
            ->count();

        // Jadwal hari ini
        $hariIni = Carbon::now()->locale('id')->isoFormat('dddd');
        $jadwalHariIni = $jadwalDiampu
            ->filter(fn ($j) => $j->hari === $hariIni)
            ->values();

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
