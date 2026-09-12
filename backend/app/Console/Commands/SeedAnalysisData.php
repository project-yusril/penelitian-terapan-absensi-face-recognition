<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seed data uji analisis penelitian (PRD R-05/R-07) ke `attendance_logs`:
 * - genuine/impostor berlabel untuk kurva FAR/FRR, EER, dan θ optimal;
 * - log `checkin_failed` & `geofence_valid` untuk evaluasi geofence;
 * - log `concurrent_level` untuk uji simultan.
 *
 * Idempoten: tanpa `--force` hanya berjalan bila belum ada data berlabel.
 */
class SeedAnalysisData extends Command
{
    protected $signature = 'attendance:seed-analysis-data {--force : Hapus data seeder lama lalu buat ulang}';

    protected $description = 'Seed data uji analisis FAR/FRR (genuine/impostor), geofence & uji simultan (R-05/R-07)';

    public function handle(): int
    {
        if (DB::table('attendance_logs')->whereNotNull('metadata->label')->exists() && ! $this->option('force')) {
            $this->warn('Data berlabel sudah ada. Jalankan dengan --force untuk mengganti.');

            return self::SUCCESS;
        }

        if ($this->option('force')) {
            $deleted = DB::table('attendance_logs')
                ->where('is_test_mode', true)
                ->where(function ($q) {
                    $q->whereNotNull('metadata->label')
                        ->orWhereNotNull('metadata->concurrent_level')
                        ->orWhere('keterangan', 'like', 'Seeder analisis R-05%');
                })
                ->delete();
            $this->info("Hapus {$deleted} log seeder lama.");
        }

        $mahasiswaIds = DB::table('users')
            ->join('user_roles', 'user_roles.user_id', '=', 'users.id')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.name', 'mahasiswa')
            ->pluck('users.id')
            ->all();

        if (empty($mahasiswaIds)) {
            $this->error('Tidak ada akun mahasiswa untuk mengaitkan log.');

            return self::FAILURE;
        }

        $count = count($mahasiswaIds);
        $baseLat = -0.05465;
        $baseLon = 109.34594;
        $devices = ['SM-A075F', 'SM-A055F', 'POCO X5', 'Redmi Note 11', 'Infinix Hot 30'];
        $os = ['Android 14', 'Android 15', 'Android 16'];
        $liveness = ['blink', 'turn_right', 'smile', 'nod'];

        $logs = [];

        // genuine = wajah sama (0.05–0.85, pusat 0.35); impostor = wajah beda (0.55–1.45, pusat 1.05).
        for ($i = 0; $i < 400; $i++) {
            $logs[] = $this->labeledRow(
                $mahasiswaIds[$i % $count], 'genuine',
                $this->gauss(0.35, 0.12, 0.05, 0.85),
                $baseLat, $baseLon, $devices, $os, $liveness, $i
            );
        }
        for ($i = 0; $i < 400; $i++) {
            $logs[] = $this->labeledRow(
                $mahasiswaIds[$i % $count], 'impostor',
                $this->gauss(1.05, 0.18, 0.55, 1.45),
                $baseLat, $baseLon, $devices, $os, $liveness, 400 + $i
            );
        }

        // checkin_failed tanpa label (kegagalan nyata) untuk rasio sukses geofence.
        for ($i = 0; $i < 20; $i++) {
            $logs[] = $this->plainRow(
                $mahasiswaIds[$i % $count], 'checkin_failed',
                $this->gauss(0.90, 0.20, 0.40, 1.30),
                $baseLat, $baseLon, $devices, $os, $liveness, 800 + $i
            );
        }

        // geofence_valid untuk distribusi jarak (tanpa face).
        for ($i = 0; $i < 40; $i++) {
            $logs[] = $this->geofenceRow($mahasiswaIds[$i % $count], $baseLat, $baseLon, $devices, $os, 820 + $i);
        }

        // uji simultan: level 1,5,10,15,20 × 12.
        $seed = 860;
        foreach ([1, 5, 10, 15, 20] as $level) {
            for ($j = 0; $j < 12; $j++, $seed++) {
                $logs[] = $this->concurrentRow($mahasiswaIds[$seed % $count], $level, $baseLat, $baseLon, $devices, $os, $seed);
            }
        }

        foreach (array_chunk($logs, 500) as $chunk) {
            DB::table('attendance_logs')->insert($chunk);
        }

        $this->info('Seed selesai: '.count($logs).' log '
            .'(genuine=400, impostor=400, failed=20, geofence=40, simultan=60).');

        return self::SUCCESS;
    }

    private function labeledRow(int $userId, string $label, float $distance, float $lat, float $lon, array $devices, array $os, array $liveness, int $seed): array
    {
        $row = $this->baseRow($userId, 'checkin_success', $distance, $lat, $lon, $devices, $os, $liveness, $seed);
        $row['test_type'] = $label;
        $row['metadata'] = json_encode(['label' => $label, 'is_test_mode' => true, 'keterlambatan_menit' => mt_rand(0, 120)]);
        $row['keterangan'] = "Seeder analisis R-05: {$label}";

        return $row;
    }

    private function plainRow(int $userId, string $action, float $distance, float $lat, float $lon, array $devices, array $os, array $liveness, int $seed): array
    {
        $row = $this->baseRow($userId, $action, $distance, $lat, $lon, $devices, $os, $liveness, $seed);
        $row['test_type'] = null;
        $row['metadata'] = json_encode(['is_test_mode' => true, 'error_message' => 'face_distance di atas ambang']);
        $row['keterangan'] = 'Seeder analisis R-05: failed';

        return $row;
    }

    private function geofenceRow(int $userId, float $lat, float $lon, array $devices, array $os, int $seed): array
    {
        $row = $this->baseRow($userId, 'geofence_valid', null, $lat, $lon, $devices, $os, ['blink'], $seed);
        $row['test_type'] = null;
        $row['face_distance'] = null;
        $row['liveness_challenge'] = null;
        $row['metadata'] = json_encode(['is_test_mode' => true]);
        $row['keterangan'] = 'Seeder analisis R-05: geofence';

        return $row;
    }

    private function concurrentRow(int $userId, int $level, float $lat, float $lon, array $devices, array $os, int $seed): array
    {
        $row = $this->baseRow($userId, 'checkin_success', $this->gauss(0.45, 0.15, 0.15, 0.90), $lat, $lon, $devices, $os, ['smile'], $seed);
        $row['test_type'] = null;
        $latency = mt_rand(180, 320) + $level * 22;
        $success = mt_rand(1, 100) <= 96; // 96% sukses
        $row['inference_time_ms'] = $latency;
        $row['metadata'] = json_encode(['is_test_mode' => true, 'concurrent_level' => $level, 'success' => $success, 'latency_ms' => $latency]);
        $row['keterangan'] = "Seeder analisis R-05: concurrent level {$level}";

        return $row;
    }

    private function baseRow(int $userId, string $action, ?float $faceDistance, float $lat, float $lon, array $devices, array $os, array $liveness, int $seed): array
    {
        $daysAgo = mt_rand(0, 28);

        return [
            'attendance_id' => null,
            'user_id' => $userId,
            'action' => $action,
            'status_before' => null,
            'status_after' => null,
            'latitude' => $lat + $this->jitter(),
            'longitude' => $lon + $this->jitter(),
            'distance_to_geofence' => round(mt_rand(50, 9000) / 100, 2),
            'face_distance' => $faceDistance === null ? null : round($faceDistance, 6),
            'face_threshold' => 1.0,
            'liveness_challenge' => $liveness[$seed % count($liveness)],
            'inference_time_ms' => mt_rand(200, 850),
            'device_model' => $devices[$seed % count($devices)],
            'device_os' => $os[$seed % count($os)],
            'app_version' => '1.0.0',
            'gps_accuracy' => round(mt_rand(300, 2000) / 100, 2),
            'is_test_mode' => true,
            'test_type' => null,
            'error_message' => null,
            'created_at' => now()->subDays($daysAgo)->subMinutes(mt_rand(0, 600)),
        ];
    }

    /**
     * Distribusi normal (Box–Muller) dibatasi ke [min, max].
     */
    private function gauss(float $mean, float $std, float $min, float $max): float
    {
        $u1 = max(1e-9, mt_rand() / mt_getrandmax());
        $u2 = mt_rand() / mt_getrandmax();
        $z = sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2);

        return max($min, min($max, $mean + $std * $z));
    }

    private function jitter(): float
    {
        return round((mt_rand(-8000, 8000) / 1000000), 7);
    }
}
