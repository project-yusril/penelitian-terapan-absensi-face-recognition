<?php

namespace Tests\Feature;

use App\Http\Controllers\Web\AnalysisController;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use ReflectionMethod;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression R-04: `prodi_id` harus mempersempit dataset penelitian, bukan
 * hanya memilih `face_threshold`.
 *
 * Sebelum perbaikan, FAR/FRR dihitung dari kumpulan genuine/impostor global
 * terhadap ambang milik satu prodi, sehingga setiap prodi menghasilkan angka
 * yang sama persis — tidak valid untuk laporan penelitian.
 */
class AnalysisProdiScopeTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private string $token;

    private int $prodiTi;

    private int $prodiTe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $this->prodiTi = Prodi::where('kode', 'TI')->value('id');
        $this->prodiTe = Prodi::where('kode', 'TE')->value('id');

        $admin = User::factory()->create([
            'email' => 'analisis-admin@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'not_required',
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first()->id);

        $this->token = $this->postJson('/api/auth/login', [
            'login' => 'analisis-admin@test.com',
            'password' => '12345678',
        ])->json('data.token');
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    private function student(string $nim, int $prodiId): User
    {
        return User::create([
            'nama' => "Mahasiswa {$nim}",
            'email' => strtolower($nim).'@test.com',
            'password' => Hash::make('12345678'),
            'nim' => $nim,
            'prodi_id' => $prodiId,
            'status' => 'aktif',
        ]);
    }

    /**
     * @param  array<int, float>  $distances
     */
    private function labeledLogs(User $user, string $label, array $distances): void
    {
        foreach ($distances as $distance) {
            DB::table('attendance_logs')->insert([
                'user_id' => $user->id,
                'action' => 'face_verify',
                'face_distance' => $distance,
                'metadata' => json_encode(['label' => $label]),
                'created_at' => now(),
            ]);
        }
    }

    private function outcomeLog(User $user, string $action): void
    {
        DB::table('attendance_logs')->insert([
            'user_id' => $user->id,
            'action' => $action,
            'created_at' => now(),
        ]);
    }

    /**
     * Dua prodi dengan sebaran distance yang sengaja berlawanan.
     * Pada θ=0.5: TI sempurna (FAR 0 / FRR 0), TE gagal total (FAR 100 / FRR 100).
     */
    private function seedOpposingProdiDatasets(): void
    {
        $ti = $this->student('TI-1', $this->prodiTi);
        $this->labeledLogs($ti, 'genuine', [0.20, 0.30]);
        $this->labeledLogs($ti, 'impostor', [0.90, 0.95]);

        $te = $this->student('TE-1', $this->prodiTe);
        $this->labeledLogs($te, 'genuine', [0.80, 0.90]);
        $this->labeledLogs($te, 'impostor', [0.10, 0.20]);
    }

    private function faceVerification(array $query): array
    {
        return $this->withHeaders($this->auth())
            ->getJson('/api/admin/analysis/face-verification?'.http_build_query($query))
            ->assertStatus(200)
            ->json('data');
    }

    public function test_far_frr_dataset_is_scoped_to_selected_prodi(): void
    {
        $this->seedOpposingProdiDatasets();

        // Prodi TI: seluruh genuine di bawah ambang, seluruh impostor di atas.
        $ti = $this->faceVerification(['prodi_id' => $this->prodiTi, 'threshold' => 0.5]);
        $this->assertSame(2, $ti['test_data']['genuine_count']);
        $this->assertSame(2, $ti['test_data']['impostor_count']);
        $this->assertEqualsWithDelta(0, $ti['test_data']['far'], 0.001);
        $this->assertEqualsWithDelta(0, $ti['test_data']['frr'], 0.001);

        // Prodi TE: kebalikannya. Kode lama mengembalikan angka identik dengan TI
        // karena dataset tidak pernah difilter.
        $te = $this->faceVerification(['prodi_id' => $this->prodiTe, 'threshold' => 0.5]);
        $this->assertSame(2, $te['test_data']['genuine_count']);
        $this->assertSame(2, $te['test_data']['impostor_count']);
        $this->assertEqualsWithDelta(100, $te['test_data']['far'], 0.001);
        $this->assertEqualsWithDelta(100, $te['test_data']['frr'], 0.001);

        // Tanpa filter: gabungan kedua prodi.
        $all = $this->faceVerification(['threshold' => 0.5]);
        $this->assertSame(4, $all['test_data']['genuine_count']);
        $this->assertSame(4, $all['test_data']['impostor_count']);
        $this->assertEqualsWithDelta(50, $all['test_data']['far'], 0.001);
        $this->assertEqualsWithDelta(50, $all['test_data']['frr'], 0.001);
    }

    public function test_sweep_and_eer_follow_the_scoped_dataset(): void
    {
        $this->seedOpposingProdiDatasets();

        $ti = $this->faceVerification(['prodi_id' => $this->prodiTi]);
        $te = $this->faceVerification(['prodi_id' => $this->prodiTe]);

        // TI terpisah bersih pada seluruh rentang θ (EER 0), TE tumpang tindih
        // total (EER 100). Jika dataset masih global, kedua prodi menghasilkan
        // kurva dan EER yang sama persis.
        $this->assertEqualsWithDelta(0, $ti['eer'], 0.001);
        $this->assertEqualsWithDelta(100, $te['eer'], 0.001);
        $this->assertNotEquals($ti['sweep'], $te['sweep']);
    }

    public function test_unknown_prodi_is_rejected_instead_of_returning_empty_dataset(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/admin/analysis/face-verification?prodi_id=999999')
            ->assertStatus(422)
            ->assertJsonValidationErrors('prodi_id');
    }

    public function test_archived_student_still_counts_in_prodi_scoped_dataset(): void
    {
        $active = $this->student('TI-2', $this->prodiTi);
        $this->labeledLogs($active, 'genuine', [0.20]);

        $archived = $this->student('TI-3', $this->prodiTi);
        $this->labeledLogs($archived, 'genuine', [0.25]);
        $archived->delete();

        $this->assertSoftDeleted('users', ['id' => $archived->id]);

        // M-19 menjadikan arsip sebagai cara resmi menonaktifkan master tanpa
        // menghancurkan riwayat, sehingga baris riwayatnya tidak boleh hilang
        // begitu filter prodi diaktifkan.
        $scoped = $this->faceVerification(['prodi_id' => $this->prodiTi, 'threshold' => 0.5]);
        $unscoped = $this->faceVerification(['threshold' => 0.5]);

        $this->assertSame(2, $scoped['test_data']['genuine_count']);
        $this->assertSame(
            $unscoped['test_data']['genuine_count'],
            $scoped['test_data']['genuine_count']
        );
    }

    public function test_geofence_success_rate_is_scoped_per_prodi(): void
    {
        $ti = $this->student('TI-4', $this->prodiTi);
        $this->outcomeLog($ti, 'checkin_success');
        $this->outcomeLog($ti, 'checkin_success');

        $te = $this->student('TE-2', $this->prodiTe);
        $this->outcomeLog($te, 'checkin_failed');
        $this->outcomeLog($te, 'checkin_failed');

        $method = new ReflectionMethod(AnalysisController::class, 'geofenceData');
        $method->setAccessible(true);
        $controller = app(AnalysisController::class);

        $this->assertSame(100.0, $method->invoke($controller, $this->prodiTi)['success_rate']);
        $this->assertSame(0.0, $method->invoke($controller, $this->prodiTe)['success_rate']);
        $this->assertSame(50.0, $method->invoke($controller, null)['success_rate']);
    }

    private function prodiAdminToken(string $email, int $prodiId, string $role = 'admin_prodi'): string
    {
        $admin = User::factory()->create([
            'email' => $email,
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'not_required',
            'prodi_id' => $prodiId,
        ]);
        $admin->roles()->attach(Role::where('name', $role)->first()->id);

        return $this->postJson('/api/auth/login', [
            'login' => $email,
            'password' => '12345678',
        ])->json('data.token');
    }

    /**
     * MS-01 menemukan ini: `/api/admin/analysis/*` terbuka untuk `admin_prodi`
     * dan `admin_jurusan`, tetapi datanya lintas prodi dan tidak pernah
     * di-scope ke aktor. Role tingkat prodi jadi bisa membaca statistik prodi
     * lain — kebocoran sejenis yang ditutup H-21 untuk monitoring/report.
     */
    public function test_prodi_admin_cannot_read_other_prodi_analysis(): void
    {
        $this->seedOpposingProdiDatasets();

        $token = $this->prodiAdminToken('admin-ti@test.com', $this->prodiTi);

        // Meminta prodi lain secara eksplisit ditolak, bukan diam-diam dilayani.
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/admin/analysis/face-verification?prodi_id='.$this->prodiTe)
            ->assertStatus(403);
    }

    public function test_prodi_admin_is_forced_to_own_prodi_when_filter_omitted(): void
    {
        $this->seedOpposingProdiDatasets();

        $token = $this->prodiAdminToken('admin-ti2@test.com', $this->prodiTi);

        // Tanpa filter, aktor tingkat prodi tidak boleh menerima gabungan
        // seluruh prodi; scope dipersempit ke prodinya sendiri.
        $data = $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/admin/analysis/face-verification?threshold=0.5')
            ->assertStatus(200)
            ->json('data');

        $this->assertSame(2, $data['test_data']['genuine_count']);
        $this->assertSame(2, $data['test_data']['impostor_count']);
        $this->assertEqualsWithDelta(0, $data['test_data']['far'], 0.001);
        $this->assertEqualsWithDelta(0, $data['test_data']['frr'], 0.001);
    }

    public function test_super_admin_still_sees_combined_dataset(): void
    {
        $this->seedOpposingProdiDatasets();

        $all = $this->faceVerification(['threshold' => 0.5]);

        $this->assertSame(4, $all['test_data']['genuine_count']);
        $this->assertSame(4, $all['test_data']['impostor_count']);
    }

    public function test_prodi_admin_without_prodi_is_denied(): void
    {
        $this->seedOpposingProdiDatasets();

        $admin = User::factory()->create([
            'email' => 'admin-tanpa-prodi@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'not_required',
            'prodi_id' => null,
        ]);
        $admin->roles()->attach(Role::where('name', 'admin_prodi')->first()->id);

        $token = $this->postJson('/api/auth/login', [
            'login' => 'admin-tanpa-prodi@test.com',
            'password' => '12345678',
        ])->json('data.token');

        // Fail-closed, bukan jatuh ke dataset global.
        $this->withHeaders(['Authorization' => "Bearer {$token}"])
            ->getJson('/api/admin/analysis/face-verification')
            ->assertStatus(403);
    }

    /**
     * Regression R-01 untuk endpoint API: definisinya harus sama dengan
     * `Web\AnalysisController::geofenceData`. Endpoint ini sebelumnya masih
     * menghitung `geofence_valid` sebagai keberhasilan.
     */
    public function test_api_geofence_counts_checkin_success_not_geofence_valid(): void
    {
        $ti = $this->student('TI-5', $this->prodiTi);
        $this->outcomeLog($ti, 'geofence_valid');
        $this->outcomeLog($ti, 'geofence_valid');
        $this->outcomeLog($ti, 'geofence_valid');
        $this->outcomeLog($ti, 'geofence_valid');
        $this->outcomeLog($ti, 'checkin_success');
        $this->outcomeLog($ti, 'checkin_failed');
        $this->outcomeLog($ti, 'checkin_failed');
        $this->outcomeLog($ti, 'checkin_failed');

        $data = $this->withHeaders($this->auth())
            ->getJson('/api/admin/analysis/geofence')
            ->assertStatus(200)
            ->json('data');

        // Kode lama: total_attempts = 8 dan success = 4 (geofence_valid) => 50%.
        $this->assertSame(4, $data['total_attempts']);
        $this->assertSame(1, $data['success']);
        $this->assertSame(3, $data['failed']);
        $this->assertEqualsWithDelta(25, $data['success_rate'], 0.001);
    }
}
