<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\ProdiSetting;
use App\Models\SpRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AnalysisController extends Controller
{
    /**
     * Data evaluasi geofence (distribusi jarak, success rate)
     */
    public function geofence(Request $request): JsonResponse
    {
        $query = AttendanceLog::whereIn('action', ['checkin_success', 'checkin_failed', 'geofence_valid', 'geofence_invalid']);

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        $logs = $query->get();

        $distances = $logs->map(fn ($log) => $log->distance_to_geofence)->filter()->values();

        $totalAttempts = $logs->count();
        $successAttempts = $logs->filter(fn ($log) => $log->action === 'geofence_valid')->count();
        $failedAttempts = $totalAttempts - $successAttempts;

        return $this->success([
            'total_attempts' => $totalAttempts,
            'success' => $successAttempts,
            'failed' => $failedAttempts,
            'success_rate' => $totalAttempts > 0 ? round(($successAttempts / $totalAttempts) * 100, 2) : 0,
            'distance_stats' => [
                'min' => $distances->min(),
                'max' => $distances->max(),
                'avg' => $distances->count() > 0 ? round($distances->avg(), 2) : null,
                'median' => $distances->count() > 0 ? $distances->sort()->values()->get((int) floor($distances->count() / 2)) : null,
            ],
            'distribution' => [
                '0-10m' => $distances->filter(fn ($d) => $d <= 10)->count(),
                '10-25m' => $distances->filter(fn ($d) => $d > 10 && $d <= 25)->count(),
                '25-50m' => $distances->filter(fn ($d) => $d > 25 && $d <= 50)->count(),
                '50-100m' => $distances->filter(fn ($d) => $d > 50 && $d <= 100)->count(),
                '>100m' => $distances->filter(fn ($d) => $d > 100)->count(),
            ],
        ]);
    }

    /**
     * Data evaluasi face verification (distribusi distance, FAR, FRR)
     */
    public function faceVerification(Request $request): JsonResponse
    {
        $query = Attendance::whereNotNull('checkin_face_distance');

        if ($request->filled('date_from')) {
            $query->where('tanggal', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('tanggal', '<=', $request->date_to);
        }

        $attendances = $query->get();

        $distances = $attendances->pluck('checkin_face_distance')->filter();

        // Hitung FAR/FRR dari labeled test data
        $genuineLogs = AttendanceLog::whereJsonContains('metadata->label', 'genuine')->get();
        $impostorLogs = AttendanceLog::whereJsonContains('metadata->label', 'impostor')->get();

        $genuineDistances = $genuineLogs->map(fn ($l) => $l->face_distance)->filter()->values();
        $impostorDistances = $impostorLogs->map(fn ($l) => $l->face_distance)->filter()->values();

        // R-02: threshold default = sumber kebenaran tunggal (ProdiSetting.face_threshold).
        // Bisa di-override per prodi via ?prodi_id, atau eksplisit via ?threshold.
        $prodiThreshold = $this->resolveProdiThreshold($request->get('prodi_id'));
        $threshold = (float) $request->get('threshold', $prodiThreshold);

        [$far, $frr] = $this->computeFarFrr($genuineDistances, $impostorDistances, $threshold);

        // R-02/R-05: sweep FAR/FRR pada rentang θ untuk menentukan EER & θ optimal.
        $sweep = $this->sweepFarFrr($genuineDistances, $impostorDistances);

        return $this->success([
            'total_verifications' => $distances->count(),
            'distance_stats' => [
                'min' => $distances->min(),
                'max' => $distances->max(),
                'avg' => $distances->count() > 0 ? round($distances->avg(), 4) : null,
            ],
            'distribution' => [
                '0-0.2' => $distances->filter(fn ($d) => $d <= 0.2)->count(),
                '0.2-0.4' => $distances->filter(fn ($d) => $d > 0.2 && $d <= 0.4)->count(),
                '0.4-0.6' => $distances->filter(fn ($d) => $d > 0.4 && $d <= 0.6)->count(),
                '0.6-0.8' => $distances->filter(fn ($d) => $d > 0.6 && $d <= 0.8)->count(),
                '>0.8' => $distances->filter(fn ($d) => $d > 0.8)->count(),
            ],
            'test_data' => [
                'genuine_count' => $genuineDistances->count(),
                'impostor_count' => $impostorDistances->count(),
                'threshold' => $threshold,
                'threshold_source' => $request->filled('threshold') ? 'manual' : 'prodi_setting',
                'far' => $far,
                'frr' => $frr,
            ],
            // R-02: data kurva FAR/FRR + titik EER & θ optimal untuk laporan penelitian
            'sweep' => $sweep['points'],
            'eer' => $sweep['eer'],
            'optimal_threshold' => $sweep['optimal_threshold'],
        ]);
    }

    /**
     * R-02: ambil face_threshold dari ProdiSetting sebagai sumber kebenaran tunggal.
     * Fallback ke 1.00 (selaras AttendanceController & EnrollmentController).
     */
    private function resolveProdiThreshold($prodiId): float
    {
        if ($prodiId) {
            $setting = ProdiSetting::where('prodi_id', $prodiId)->first();
            if ($setting && $setting->face_threshold !== null) {
                return (float) $setting->face_threshold;
            }
        }

        // Jika hanya ada satu prodi/seting, pakai itu; jika tidak, default 1.00.
        $only = ProdiSetting::whereNotNull('face_threshold')->first();

        return (float) ($only?->face_threshold ?? 1.00);
    }

    /**
     * Hitung FAR & FRR (persen) pada threshold tertentu.
     * Match jika distance <= threshold. FAR = impostor diterima; FRR = genuine ditolak.
     *
     * @return array{0: float|null, 1: float|null} [far, frr]
     */
    private function computeFarFrr($genuineDistances, $impostorDistances, float $threshold): array
    {
        $far = $impostorDistances->count() > 0
            ? round($impostorDistances->filter(fn ($d) => $d <= $threshold)->count() / $impostorDistances->count() * 100, 2)
            : null;
        $frr = $genuineDistances->count() > 0
            ? round($genuineDistances->filter(fn ($d) => $d > $threshold)->count() / $genuineDistances->count() * 100, 2)
            : null;

        return [$far, $frr];
    }

    /**
     * R-02/R-05: sweep θ dari 0.30..1.40 (step 0.05), hitung FAR/FRR per titik,
     * lalu tentukan Equal Error Rate (EER) & θ optimal (|FAR-FRR| minimal).
     */
    private function sweepFarFrr($genuineDistances, $impostorDistances): array
    {
        $points = [];
        $eer = null;
        $optimalThreshold = null;
        $minDiff = null;

        if ($genuineDistances->count() === 0 || $impostorDistances->count() === 0) {
            return ['points' => $points, 'eer' => null, 'optimal_threshold' => null];
        }

        for ($t = 0.30; $t <= 1.4001; $t += 0.05) {
            $threshold = round($t, 2);
            [$far, $frr] = $this->computeFarFrr($genuineDistances, $impostorDistances, $threshold);

            $points[] = [
                'threshold' => $threshold,
                'far' => $far,
                'frr' => $frr,
            ];

            $diff = abs(($far ?? 0) - ($frr ?? 0));
            if ($minDiff === null || $diff < $minDiff) {
                $minDiff = $diff;
                $optimalThreshold = $threshold;
                $eer = round((($far ?? 0) + ($frr ?? 0)) / 2, 2);
            }
        }

        return [
            'points' => $points,
            'eer' => $eer,
            'optimal_threshold' => $optimalThreshold,
        ];
    }

    /**
     * Data latensi (per device, rata-rata, min, max, P95)
     */
    public function latency(Request $request): JsonResponse
    {
        $query = AttendanceLog::whereNotNull('inference_time_ms');

        if ($request->filled('date_from')) {
            $query->where('created_at', '>=', $request->date_from);
        }
        if ($request->filled('date_to')) {
            $query->where('created_at', '<=', $request->date_to);
        }

        $logs = $query->get();
        $latencies = $logs->map(fn ($l) => $l->inference_time_ms)->filter()->sort()->values();

        $p95Index = (int) ceil($latencies->count() * 0.95) - 1;

        return $this->success([
            'total_records' => $latencies->count(),
            'stats' => [
                'min' => $latencies->min(),
                'max' => $latencies->max(),
                'avg' => $latencies->count() > 0 ? round($latencies->avg(), 2) : null,
                'p95' => $latencies->count() > 0 ? $latencies->get(max(0, $p95Index)) : null,
                'median' => $latencies->count() > 0 ? $latencies->get((int) floor($latencies->count() / 2)) : null,
            ],
            'per_device' => $logs->groupBy(fn ($l) => $l->device_model ?? 'unknown')
                ->map(function ($group) {
                    $lats = $group->map(fn ($l) => $l->inference_time_ms)->filter();

                    return [
                        'count' => $lats->count(),
                        'avg' => $lats->count() > 0 ? round($lats->avg(), 2) : null,
                        'min' => $lats->min(),
                        'max' => $lats->max(),
                    ];
                }),
        ]);
    }

    /**
     * Data kehadiran & SP (distribusi, trend)
     */
    public function attendanceSp(Request $request): JsonResponse
    {
        $request->validate([
            'semester_id' => 'nullable|exists:semesters,id',
        ]);

        // Distribusi status kehadiran
        $statusDistribution = Attendance::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status');

        // Distribusi SP level
        $spDistribution = AlphaAccumulation::select('sp_status', DB::raw('COUNT(*) as count'))
            ->groupBy('sp_status')
            ->pluck('count', 'sp_status');

        // Trend kehadiran per minggu (4 minggu terakhir)
        $weeklyTrend = [];
        for ($i = 3; $i >= 0; $i--) {
            $startOfWeek = now()->subWeeks($i)->startOfWeek();
            $endOfWeek = now()->subWeeks($i)->endOfWeek();

            $weekData = Attendance::whereBetween('tanggal', [$startOfWeek, $endOfWeek])
                ->select(DB::raw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('hadir', 'hadir_terlambat') THEN 1 ELSE 0 END) as hadir,
                    SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha
                "))
                ->first();

            $weeklyTrend[] = [
                'week' => $startOfWeek->format('Y-m-d'),
                'total' => $weekData->total ?? 0,
                'hadir' => $weekData->hadir ?? 0,
                'alpha' => $weekData->alpha ?? 0,
                'persentase_hadir' => ($weekData->total ?? 0) > 0
                    ? round(($weekData->hadir / $weekData->total) * 100, 1)
                    : 0,
            ];
        }

        // SP records count
        $spRecordsCount = SpRecord::select('sp_level', DB::raw('COUNT(*) as count'))
            ->groupBy('sp_level')
            ->pluck('count', 'sp_level');

        return $this->success([
            'status_distribution' => $statusDistribution,
            'sp_level_distribution' => $spDistribution,
            'weekly_trend' => $weeklyTrend,
            'sp_records' => $spRecordsCount,
        ]);
    }

    /**
     * Data uji simultan (response time per concurrent level)
     */
    public function simultaneousTest(Request $request): JsonResponse
    {
        // Data dari attendance_logs yang punya metadata concurrent_level
        $logs = AttendanceLog::whereNotNull('metadata->concurrent_level')
            ->get();

        $perLevel = $logs->groupBy(fn ($l) => $l->metadata['concurrent_level'] ?? 0)
            ->map(function ($group) {
                $latencies = $group->map(fn ($l) => $l->inference_time_ms ?? $l->metadata['latency_ms'] ?? null)->filter();

                return [
                    'count' => $group->count(),
                    'avg_latency' => $latencies->count() > 0 ? round($latencies->avg(), 2) : null,
                    'max_latency' => $latencies->max(),
                    'success_rate' => $group->count() > 0
                        ? round($group->filter(fn ($l) => ($l->metadata['success'] ?? false))->count() / $group->count() * 100, 2)
                        : 0,
                ];
            });

        return $this->success([
            'total_tests' => $logs->count(),
            'per_concurrent_level' => $perLevel,
        ]);
    }

    /**
     * Data perbandingan konvensional vs sistem
     */
    public function conventionalComparison(Request $request): JsonResponse
    {
        $request->validate([
            'mata_kuliah_id' => 'nullable|exists:mata_kuliahs,id',
        ]);

        // Ambil data konvensional dari system_settings
        $conventionalData = DB::table('system_settings')
            ->where('key', 'like', 'conventional_data_%')
            ->get()
            ->mapWithKeys(fn ($item) => [$item->key => json_decode($item->value, true)]);

        // Ambil data sistem
        $query = Attendance::query();
        if ($request->filled('mata_kuliah_id')) {
            $query->where('mata_kuliah_id', $request->mata_kuliah_id);
        }

        // R-08: durasi efektif diambil dari kolom `durasi_efektif_menit` (diisi saat check-out,
        // lihat AttendanceController@checkOut) agar tidak null. Fallback ke TIMESTAMPDIFF
        // untuk record lama yang punya checkout_time tapi belum mengisi kolom durasi.
        $systemData = $query->select(DB::raw("
            COUNT(*) as total,
            SUM(CASE WHEN status IN ('hadir', 'hadir_terlambat') THEN 1 ELSE 0 END) as hadir,
            SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha,
            SUM(CASE WHEN checkout_time IS NOT NULL THEN 1 ELSE 0 END) as with_checkout,
            AVG(
                COALESCE(
                    durasi_efektif_menit,
                    TIMESTAMPDIFF(MINUTE, checkin_time, checkout_time)
                )
            ) as avg_duration_minutes
        "))->first();

        return $this->success([
            'conventional' => $conventionalData,
            'system' => [
                'total_records' => $systemData->total ?? 0,
                'hadir' => $systemData->hadir ?? 0,
                'alpha' => $systemData->alpha ?? 0,
                'with_checkout' => (int) ($systemData->with_checkout ?? 0),
                'avg_duration_minutes' => $systemData->avg_duration_minutes !== null
                    ? round($systemData->avg_duration_minutes, 1)
                    : null,
            ],
        ]);
    }

    /**
     * Input data konvensional untuk perbandingan
     */
    public function storeConventionalData(Request $request): JsonResponse
    {
        $request->validate([
            'mata_kuliah_id' => 'required|exists:mata_kuliahs,id',
            'tanggal' => 'required|date',
            'total_mahasiswa' => 'required|integer|min:0',
            'hadir' => 'required|integer|min:0',
            'alpha' => 'required|integer|min:0',
            'waktu_proses_menit' => 'required|numeric|min:0',
            'catatan' => 'nullable|string',
        ]);

        $key = "conventional_data_{$request->mata_kuliah_id}_{$request->tanggal}";

        DB::table('system_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => json_encode($request->only([
                'mata_kuliah_id', 'tanggal', 'total_mahasiswa',
                'hadir', 'alpha', 'waktu_proses_menit', 'catatan',
            ]))]
        );

        return $this->success(message: 'Data konvensional berhasil disimpan');
    }
}
