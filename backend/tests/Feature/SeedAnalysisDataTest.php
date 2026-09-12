<?php

namespace Tests\Feature;

use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * R-05: seeder data analisis mengisi attendance_logs berlabel (genuine/impostor),
 * log geofence & uji simultan — dan idempoten terhadap data berlabel yang ada.
 */
class SeedAnalysisDataTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        for ($i = 0; $i < 3; $i++) {
            $user = User::factory()->create(['prodi_id' => $prodi->id, 'status' => 'aktif']);
            $user->roles()->attach(Role::where('name', 'mahasiswa')->value('id'));
        }
    }

    public function test_seed_mengisi_genuine_impostor_geofence_dan_simultan(): void
    {
        $this->artisan('attendance:seed-analysis-data')->assertSuccessful();

        $this->assertSame(400, DB::table('attendance_logs')->whereJsonContains('metadata->label', 'genuine')->count());
        $this->assertSame(400, DB::table('attendance_logs')->whereJsonContains('metadata->label', 'impostor')->count());
        $this->assertSame(40, DB::table('attendance_logs')->where('action', 'geofence_valid')->where('is_test_mode', true)->count());
        $this->assertSame(60, DB::table('attendance_logs')->whereNotNull('metadata->concurrent_level')->count());
        $this->assertSame(20, DB::table('attendance_logs')->where('action', 'checkin_failed')->where('is_test_mode', true)->count());
    }

    public function test_seed_idempoten_tanpa_force(): void
    {
        $this->artisan('attendance:seed-analysis-data')->assertSuccessful();
        $before = DB::table('attendance_logs')->count();

        // Tanpa --force: tidak menambah duplikat.
        $this->artisan('attendance:seed-analysis-data')->assertSuccessful();
        $this->assertSame($before, DB::table('attendance_logs')->count());
    }

    public function test_seed_force_mengganti_data_lama(): void
    {
        $this->artisan('attendance:seed-analysis-data')->assertSuccessful();
        $before = DB::table('attendance_logs')->count();

        // --force: hapus log seeder lama, sisakan log produksi asli.
        $this->artisan('attendance:seed-analysis-data', ['--force' => true])->assertSuccessful();
        $after = DB::table('attendance_logs')->count();

        // Produksi asli (is_test_mode=false) tetap ada; total seeder tetap sama.
        $this->assertSame($before, $after);
        $this->assertSame(400, DB::table('attendance_logs')->whereJsonContains('metadata->label', 'genuine')->count());
    }
}
