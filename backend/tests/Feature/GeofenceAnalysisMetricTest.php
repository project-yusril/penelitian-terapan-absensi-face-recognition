<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\AnalysisController;
use App\Models\Prodi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression R-01: success rate geofence dihitung dari `checkin_success`,
 * bukan `geofence_valid`. Distribusi jarak tetap dari log geofence.
 */
class GeofenceAnalysisMetricTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    private function log(string $action, ?float $distance): void
    {
        DB::table('attendance_logs')->insert([
            'user_id' => $this->userId,
            'action' => $action,
            'distance_to_geofence' => $distance,
            'created_at' => now(),
        ]);
    }

    private int $userId;

    private function geofenceData(): array
    {
        $method = new ReflectionMethod(AnalysisController::class, 'geofenceData');
        $method->setAccessible(true);

        return $method->invoke(app(AnalysisController::class));
    }

    public function test_success_rate_reflects_checkin_success_not_geofence_valid(): void
    {
        $this->userId = User::create([
            'nama' => 'Analitik', 'email' => 'analitik@test.com', 'password' => 'password',
            'nim' => 'AN-1', 'prodi_id' => Prodi::where('kode', 'TI')->value('id'), 'status' => 'aktif',
        ])->id;

        // 4 geofence checks lolos, tetapi hanya 1 check-in yang benar-benar sukses.
        // Jika kode lama dipakai (menghitung geofence_valid), success_rate = 100%.
        $this->log('geofence_valid', 5.0);
        $this->log('geofence_valid', 8.0);
        $this->log('geofence_valid', 30.0);
        $this->log('geofence_valid', 120.0);

        $this->log('checkin_success', null);
        $this->log('checkin_failed', null);
        $this->log('checkin_failed', null);
        $this->log('checkin_failed', null);

        $data = $this->geofenceData();

        // Success rate = 1 sukses / 4 attempt check-in = 25%.
        $this->assertSame(4, $data['total_attempts']);
        $this->assertSame(1, $data['success']);
        $this->assertSame(3, $data['failed']);
        $this->assertSame(25.0, $data['success_rate']);

        // Distribusi jarak tetap dihitung dari 4 log geofence.
        $this->assertSame(2, $data['distribution']['0-10m']);
        $this->assertSame(1, $data['distribution']['25-50m']);
        $this->assertSame(1, $data['distribution']['>100m']);
        $this->assertSame(5.0, $data['distance_stats']['min']);
        $this->assertSame(120.0, $data['distance_stats']['max']);
    }

    public function test_zero_checkin_attempts_yields_zero_rate(): void
    {
        $this->userId = User::create([
            'nama' => 'Kosong', 'email' => 'kosong@test.com', 'password' => 'password',
            'nim' => 'AN-2', 'prodi_id' => Prodi::where('kode', 'TI')->value('id'), 'status' => 'aktif',
        ])->id;

        $this->log('geofence_valid', 10.0);

        $data = $this->geofenceData();

        $this->assertSame(0, $data['total_attempts']);
        $this->assertSame(0, $data['success_rate']);
    }
}
