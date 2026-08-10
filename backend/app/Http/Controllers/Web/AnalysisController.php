<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\AttendanceLog;
use App\Models\Prodi;
use App\Models\ProdiSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Analisis & evaluasi penelitian (PRD R-02/R-05): distribusi distance wajah,
 * kurva FAR/FRR, EER, dan θ optimal — untuk MobileFaceNet.
 */
class AnalysisController extends Controller
{
    public function index(Request $request): Response
    {
        $prodiId = $request->integer('prodi_id') ?: null;
        $threshold = $request->filled('threshold')
            ? (float) $request->threshold
            : $this->resolveProdiThreshold($prodiId);

        // M-17: hanya ambil kolom yang dipakai, bukan seluruh model row.
        $genuine = AttendanceLog::whereJsonContains('metadata->label', 'genuine')
            ->whereNotNull('face_distance')
            ->pluck('face_distance')->map(fn ($d) => (float) $d)->values();
        $impostor = AttendanceLog::whereJsonContains('metadata->label', 'impostor')
            ->whereNotNull('face_distance')
            ->pluck('face_distance')->map(fn ($d) => (float) $d)->values();

        [$far, $frr] = $this->computeFarFrr($genuine, $impostor, $threshold);
        $sweep = $this->sweepFarFrr($genuine, $impostor);

        // M-17: statistik dan distribusi dihitung database, bukan memuat
        // seluruh kolom distance ke memory.
        $verificationStats = $this->distanceAggregate();

        return Inertia::render('Analysis/Index', [
            'prodis' => Prodi::select('id', 'nama')->orderBy('nama')->get(),
            'filters' => ['prodi_id' => $prodiId, 'threshold' => $threshold],
            'geofence' => $this->geofenceData(),
            'latency' => $this->latencyData(),
            'attendanceSp' => $this->attendanceSpData(),
            'simultaneous' => $this->simultaneousData(),
            'verification' => [

                'total' => $verificationStats['total'],
                'distance_stats' => $verificationStats['distance_stats'],
                'distribution' => $verificationStats['distribution'],
                'genuine_count' => $genuine->count(),
                'impostor_count' => $impostor->count(),
                'threshold' => $threshold,
                'far' => $far,
                'frr' => $frr,
                'sweep' => $sweep['points'],
                'eer' => $sweep['eer'],
                'optimal_threshold' => $sweep['optimal_threshold'],
            ],
        ]);
    }

    /**
     * M-17: total, statistik, dan bucket distribusi dihitung dalam satu query
     * agregat sehingga tidak memuat seluruh baris attendance ke memory.
     *
     * @return array{total: int, distance_stats: array<string, float|null>, distribution: array<string, int>}
     */
    private function distanceAggregate(): array
    {
        $row = Attendance::whereNotNull('checkin_face_distance')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('MIN(checkin_face_distance) as min_distance')
            ->selectRaw('MAX(checkin_face_distance) as max_distance')
            ->selectRaw('AVG(checkin_face_distance) as avg_distance')
            ->selectRaw('SUM(CASE WHEN checkin_face_distance <= 0.2 THEN 1 ELSE 0 END) as b1')
            ->selectRaw('SUM(CASE WHEN checkin_face_distance > 0.2 AND checkin_face_distance <= 0.4 THEN 1 ELSE 0 END) as b2')
            ->selectRaw('SUM(CASE WHEN checkin_face_distance > 0.4 AND checkin_face_distance <= 0.6 THEN 1 ELSE 0 END) as b3')
            ->selectRaw('SUM(CASE WHEN checkin_face_distance > 0.6 AND checkin_face_distance <= 0.8 THEN 1 ELSE 0 END) as b4')
            ->selectRaw('SUM(CASE WHEN checkin_face_distance > 0.8 THEN 1 ELSE 0 END) as b5')
            ->first();

        $total = (int) ($row->total ?? 0);

        return [
            'total' => $total,
            'distance_stats' => [
                'min' => $total > 0 ? (float) $row->min_distance : null,
                'max' => $total > 0 ? (float) $row->max_distance : null,
                'avg' => $total > 0 ? round((float) $row->avg_distance, 4) : null,
            ],
            'distribution' => [
                '0-0.2' => (int) ($row->b1 ?? 0),
                '0.2-0.4' => (int) ($row->b2 ?? 0),
                '0.4-0.6' => (int) ($row->b3 ?? 0),
                '0.6-0.8' => (int) ($row->b4 ?? 0),
                '>0.8' => (int) ($row->b5 ?? 0),
            ],
        ];
    }

    private function resolveProdiThreshold(?int $prodiId): float
    {
        if ($prodiId) {
            $s = ProdiSetting::where('prodi_id', $prodiId)->first();
            if ($s && $s->face_threshold !== null) {
                return (float) $s->face_threshold;
            }
        }
        $only = ProdiSetting::whereNotNull('face_threshold')->first();

        return (float) ($only?->face_threshold ?? 1.00);
    }

    private function computeFarFrr(Collection $genuine, Collection $impostor, float $threshold): array
    {
        $far = $impostor->count() > 0
            ? round($impostor->filter(fn ($d) => $d <= $threshold)->count() / $impostor->count() * 100, 2)
            : null;
        $frr = $genuine->count() > 0
            ? round($genuine->filter(fn ($d) => $d > $threshold)->count() / $genuine->count() * 100, 2)
            : null;

        return [$far, $frr];
    }

    private function sweepFarFrr(Collection $genuine, Collection $impostor): array
    {
        $points = [];
        $eer = null;
        $optimal = null;
        $minDiff = null;

        if ($genuine->count() === 0 || $impostor->count() === 0) {
            return ['points' => $points, 'eer' => null, 'optimal_threshold' => null];
        }

        for ($t = 0.30; $t <= 1.4001; $t += 0.05) {
            $threshold = round($t, 2);
            [$far, $frr] = $this->computeFarFrr($genuine, $impostor, $threshold);
            $points[] = ['threshold' => $threshold, 'far' => $far, 'frr' => $frr];

            $diff = abs(($far ?? 0) - ($frr ?? 0));
            if ($minDiff === null || $diff < $minDiff) {
                $minDiff = $diff;
                $optimal = $threshold;
                $eer = round((($far ?? 0) + ($frr ?? 0)) / 2, 2);
            }
        }

        return ['points' => $points, 'eer' => $eer, 'optimal_threshold' => $optimal];
    }

    /**
     * M-17: agregasi geofence dilakukan database, bukan memuat seluruh log.
     *
     * R-01: success rate absensi diukur dari `checkin_success` vs
     * `checkin_failed`, bukan dari `geofence_valid`. `geofence_valid` hanya
     * berarti pemeriksaan geofence lolos pada satu langkah; sebuah check-in
     * masih bisa gagal setelahnya (mis. face/liveness), sehingga memakainya
     * sebagai keberhasilan akan melebih-lebihkan angka. Distribusi jarak tetap
     * dihitung dari log geofence karena `distance_to_geofence` hanya tercatat
     * di sana.
     */
    private function geofenceData(): array
    {
        $outcome = AttendanceLog::whereIn('action', ['checkin_success', 'checkin_failed'])
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN action = 'checkin_success' THEN 1 ELSE 0 END) as success")
            ->first();

        $distance = AttendanceLog::whereIn('action', ['geofence_valid', 'geofence_invalid'])
            ->selectRaw('MIN(distance_to_geofence) as min_distance')
            ->selectRaw('MAX(distance_to_geofence) as max_distance')
            ->selectRaw('AVG(distance_to_geofence) as avg_distance')
            ->selectRaw('SUM(CASE WHEN distance_to_geofence <= 10 THEN 1 ELSE 0 END) as b1')
            ->selectRaw('SUM(CASE WHEN distance_to_geofence > 10 AND distance_to_geofence <= 25 THEN 1 ELSE 0 END) as b2')
            ->selectRaw('SUM(CASE WHEN distance_to_geofence > 25 AND distance_to_geofence <= 50 THEN 1 ELSE 0 END) as b3')
            ->selectRaw('SUM(CASE WHEN distance_to_geofence > 50 AND distance_to_geofence <= 100 THEN 1 ELSE 0 END) as b4')
            ->selectRaw('SUM(CASE WHEN distance_to_geofence > 100 THEN 1 ELSE 0 END) as b5')
            ->selectRaw('COUNT(distance_to_geofence) as distance_count')
            ->first();

        $total = (int) ($outcome->total ?? 0);
        $success = (int) ($outcome->success ?? 0);
        $distanceCount = (int) ($distance->distance_count ?? 0);

        return [
            'total_attempts' => $total,
            'success' => $success,
            'failed' => $total - $success,
            'success_rate' => $total > 0 ? round($success / $total * 100, 2) : 0,
            'distance_stats' => [
                'min' => $distanceCount > 0 ? (float) $distance->min_distance : null,
                'max' => $distanceCount > 0 ? (float) $distance->max_distance : null,
                'avg' => $distanceCount > 0 ? round((float) $distance->avg_distance, 2) : null,
            ],
            'distribution' => [
                '0-10m' => (int) ($distance->b1 ?? 0),
                '10-25m' => (int) ($distance->b2 ?? 0),
                '25-50m' => (int) ($distance->b3 ?? 0),
                '50-100m' => (int) ($distance->b4 ?? 0),
                '>100m' => (int) ($distance->b5 ?? 0),
            ],
        ];
    }

    /**
     * M-17: statistik dasar dan agregasi per device dihitung database.
     * Percentile memerlukan nilai terurut, sehingga hanya satu kolom numerik
     * yang diambil (bukan seluruh model row) dan sudah diurutkan oleh SQL.
     */
    private function latencyData(): array
    {
        $stats = AttendanceLog::whereNotNull('inference_time_ms')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('MIN(inference_time_ms) as min_latency')
            ->selectRaw('MAX(inference_time_ms) as max_latency')
            ->selectRaw('AVG(inference_time_ms) as avg_latency')
            ->first();

        $total = (int) ($stats->total ?? 0);

        $p95 = null;
        $median = null;
        if ($total > 0) {
            $p95Offset = max(0, (int) ceil($total * 0.95) - 1);
            $medianOffset = (int) floor($total / 2);

            $p95 = AttendanceLog::whereNotNull('inference_time_ms')
                ->orderBy('inference_time_ms')
                ->offset($p95Offset)->limit(1)->value('inference_time_ms');
            $median = AttendanceLog::whereNotNull('inference_time_ms')
                ->orderBy('inference_time_ms')
                ->offset($medianOffset)->limit(1)->value('inference_time_ms');
        }

        $perDevice = AttendanceLog::whereNotNull('inference_time_ms')
            ->selectRaw("COALESCE(device_model, 'unknown') as device")
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('AVG(inference_time_ms) as avg_latency')
            ->selectRaw('MIN(inference_time_ms) as min_latency')
            ->selectRaw('MAX(inference_time_ms) as max_latency')
            ->groupBy('device')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->device => [
                'count' => (int) $r->count,
                'avg' => round((float) $r->avg_latency, 2),
                'min' => $r->min_latency,
                'max' => $r->max_latency,
            ]])->toArray();

        return [
            'total_records' => $total,
            'stats' => [
                'min' => $total > 0 ? $stats->min_latency : null,
                'max' => $total > 0 ? $stats->max_latency : null,
                'avg' => $total > 0 ? round((float) $stats->avg_latency, 2) : null,
                'p95' => $p95,
                'median' => $median,
            ],
            'per_device' => $perDevice,
        ];
    }

    private function attendanceSpData(): array
    {
        $statusDist = Attendance::select('status', DB::raw('COUNT(*) as count'))
            ->groupBy('status')->pluck('count', 'status');

        $spDist = AlphaAccumulation::select('sp_status', DB::raw('COUNT(*) as count'))
            ->groupBy('sp_status')->pluck('count', 'sp_status');

        $weeklyTrend = [];
        for ($i = 3; $i >= 0; $i--) {
            $start = now()->subWeeks($i)->startOfWeek();
            $end = now()->subWeeks($i)->endOfWeek();
            $w = Attendance::whereBetween('tanggal', [$start, $end])
                ->select(DB::raw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('hadir','hadir_terlambat') THEN 1 ELSE 0 END) as hadir,
                    SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha
                "))->first();
            $weeklyTrend[] = [
                'week' => $start->format('d M'),
                'total' => (int) ($w->total ?? 0),
                'hadir' => (int) ($w->hadir ?? 0),
                'alpha' => (int) ($w->alpha ?? 0),
                'persentase_hadir' => ($w->total ?? 0) > 0 ? round($w->hadir / $w->total * 100, 1) : 0,
            ];
        }

        return [
            'status_distribution' => $statusDist,
            'sp_level_distribution' => $spDist,
            'weekly_trend' => $weeklyTrend,
        ];
    }

    private function simultaneousData(): array
    {
        // M-17: hanya kolom yang dipakai agregasi yang diambil.
        $logs = AttendanceLog::whereNotNull('metadata->concurrent_level')
            ->select('metadata', 'inference_time_ms')->get();

        $perLevel = $logs->groupBy(fn ($l) => $l->metadata['concurrent_level'] ?? 0)
            ->map(function ($group) {
                $lat = $group->map(fn ($l) => $l->inference_time_ms ?? ($l->metadata['latency_ms'] ?? null))->filter();

                return [
                    'count' => $group->count(),
                    'avg_latency' => $lat->count() ? round($lat->avg(), 2) : null,
                    'max_latency' => $lat->max(),
                    'success_rate' => $group->count() > 0
                        ? round($group->filter(fn ($l) => ($l->metadata['success'] ?? false))->count() / $group->count() * 100, 2)
                        : 0,
                ];
            })->toArray();

        return [
            'total_tests' => $logs->count(),
            'per_concurrent_level' => $perLevel,
        ];
    }
}
