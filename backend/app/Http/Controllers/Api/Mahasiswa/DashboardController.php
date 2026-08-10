<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\Jadwal;
use App\Models\Semester;
use App\Services\AlphaAccumulationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $semesterAktif = Semester::where('status', 'aktif')->first();

        // M-05: guard null untuk semester aktif
        if (! $semesterAktif) {
            return $this->success([
                'jadwal_hari_ini' => [],
                'attendance_hari_ini' => [],
                'summary_semester' => [
                    'total' => 0,
                    'hadir' => 0,
                    'terlambat' => 0,
                    'alpha' => 0,
                    'izin_sakit' => 0,
                    'pending' => 0,
                    'persentase_kehadiran' => 0,
                ],
                'alpha_accumulation' => null,
                'sp_threshold' => null,
                'enrollment_status' => $user->enrollment_status,
                'warning' => 'Tidak ada semester aktif. Hubungi admin.',
            ]);
        }

        // Jadwal hari ini
        $hariIni = Carbon::now()->locale('id')->isoFormat('dddd');
        $mataKuliahIds = $user->mataKuliahs()->pluck('mata_kuliahs.id');

        $jadwalHariIni = Jadwal::with(['mataKuliah', 'geofence'])
            ->whereIn('mata_kuliah_id', $mataKuliahIds)
            ->where('hari', $hariIni)
            ->where('status', 'aktif')
            ->orderBy('jam_mulai')
            ->get();

        // Kehadiran hari ini
        $attendanceHariIni = Attendance::where('user_id', $user->id)
            ->whereDate('tanggal', today())
            ->get();

        // H-03: sertakan status attendance pada setiap jadwal hari ini
        $attMap = $attendanceHariIni->keyBy('jadwal_id');
        $jadwalHariIni = $jadwalHariIni->map(function ($j) use ($attMap) {
            $att = $attMap->get($j->id);
            $j->attendance_id = $att?->id;
            $j->attendance_status = $att?->status;
            $j->checkin_time = $att?->checkin_time;
            $j->checkout_time = $att?->checkout_time;

            return $j;
        });

        // Summary semester (M-05: pakai semester aktif yang sudah dipastikan ada)
        $summary = Attendance::where('user_id', $user->id)
            ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterAktif->id))
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                SUM(CASE WHEN status = 'hadir_terlambat' THEN 1 ELSE 0 END) as terlambat,
                SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha,
                SUM(CASE WHEN status IN ('izin', 'sakit') THEN 1 ELSE 0 END) as izin_sakit,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
            ")
            ->first();

        // Alpha accumulation
        $alphaAccumulation = AlphaAccumulation::where('user_id', $user->id)
            ->where('semester_id', $semesterAktif->id)
            ->first();

        // L-02: sp_threshold per prodi user (untuk progress bar SP di mobile)
        $alphaService = app(AlphaAccumulationService::class);
        $thresholds = $alphaService->getSpThresholds($user->prodi_id);

        // R-10: early warning SP — status "mendekati" level berikutnya (>= 80% threshold)
        // Basis akumulasi: total_alpha_jam (akumulasi jam alpha), selaras AlphaAccumulationService.
        $totalAlphaJam = (float) ($alphaAccumulation->total_alpha_jam ?? 0);
        $currentSpStatus = $alphaAccumulation->sp_status ?? 'aman';
        $earlyWarning = $alphaService->isApproachingNextLevel($user->id, $totalAlphaJam);
        $nextLevel = match ($currentSpStatus) {
            'aman' => 'sp1',
            'sp1' => 'sp2',
            'sp2' => 'sp3',
            'sp3' => 'do',
            default => null,
        };
        $nextThreshold = $nextLevel ? ($thresholds[$nextLevel] ?? null) : null;

        return $this->success([

            'jadwal_hari_ini' => $jadwalHariIni,
            'attendance_hari_ini' => $attendanceHariIni,
            'summary_semester' => [
                'total' => (int) ($summary->total ?? 0),
                'hadir' => (int) ($summary->hadir ?? 0),
                'terlambat' => (int) ($summary->terlambat ?? 0),
                'alpha' => (int) ($summary->alpha ?? 0),
                'izin_sakit' => (int) ($summary->izin_sakit ?? 0),
                'pending' => (int) ($summary->pending ?? 0),
                'persentase_kehadiran' => ($summary->total ?? 0) > 0
                    ? round((($summary->hadir + $summary->terlambat) / $summary->total) * 100, 1)
                    : 0,
            ],
            'alpha_accumulation' => $alphaAccumulation ? [
                'total_alpha_menit' => $alphaAccumulation->total_alpha_menit,
                'total_alpha_jam' => $alphaAccumulation->total_alpha_jam,
                'sp_status' => $alphaAccumulation->sp_status,
            ] : null,
            'sp_threshold' => $thresholds, // {sp1, sp2, sp3, do} dalam JAM
            // R-10: early warning — peringatan dini mendekati SP berikutnya
            'sp_early_warning' => [
                'current_level' => $currentSpStatus,
                'next_level' => $nextLevel,
                'next_threshold_jam' => $nextThreshold,
                'total_alpha_jam' => $totalAlphaJam,
                'progress_persen' => $nextThreshold
                    ? min(100, round(($totalAlphaJam / $nextThreshold) * 100, 1))
                    : null,
                'is_approaching' => $earlyWarning !== null,
                'warning_code' => $earlyWarning, // e.g. approaching_sp1 / approaching_sp2 / null
            ],
            'enrollment_status' => $user->enrollment_status,
        ]);

    }
}
