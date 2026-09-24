<?php

namespace Tests\Feature;

use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * M-25/Pos-1: endpoint geofence admin belum menerapkan scope aktor.
 *
 * Sebelum perbaikan, `admin_prodi` dari prodi A dapat melihat, mengubah, dan
 * menghapus geofence prodi B (serta geofence global) karena `prodi_id` hanya
 * filter opsional dari request dan mutasi tidak pernah memeriksa kepemilikan.
 * Index tetap memakai filter request (konvensi "filter hanya mempersempit"),
 * tetapi mutasi dan show wajib fail-closed.
 */
class GeofenceScopeTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private int $prodiTi;

    private int $prodiTe;

    private string $adminTiToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $this->prodiTi = Prodi::where('kode', 'TI')->value('id');
        $this->prodiTe = Prodi::where('kode', 'TE')->value('id');

        Geofence::create(['nama' => 'GF TI', 'latitude' => -0.1, 'longitude' => 117.1, 'radius' => 50, 'prodi_id' => $this->prodiTi]);
        Geofence::create(['nama' => 'GF TE', 'latitude' => -0.2, 'longitude' => 117.2, 'radius' => 50, 'prodi_id' => $this->prodiTe]);
        Geofence::create(['nama' => 'GF Global', 'latitude' => -0.3, 'longitude' => 117.3, 'radius' => 50, 'prodi_id' => null]);

        $admin = User::factory()->create([
            'email' => 'geo-admin-ti@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'not_required',
            'prodi_id' => $this->prodiTi,
        ]);
        $admin->roles()->attach(Role::where('name', 'admin_prodi')->first()->id);

        $this->adminTiToken = $this->postJson('/api/auth/login', [
            'login' => 'geo-admin-ti@test.com',
            'password' => '12345678',
        ])->json('data.token');
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->adminTiToken}"];
    }

    public function test_index_only_lists_own_prodi_geofences(): void
    {
        $names = collect($this->getJson('/api/admin/geofence', $this->auth())->json('data'))
            ->pluck('nama');

        $this->assertContains('GF TI', $names);
        $this->assertNotContains('GF TE', $names);
        $this->assertNotContains('GF Global', $names);
    }

    public function test_index_filter_to_other_prodi_returns_empty_not_other_data(): void
    {
        $names = collect($this->getJson('/api/admin/geofence?prodi_id='.$this->prodiTe, $this->auth())->json('data'))
            ->pluck('nama');

        $this->assertEmpty($names);
    }

    public function test_show_other_prodi_geofence_is_forbidden(): void
    {
        $id = Geofence::where('nama', 'GF TE')->value('id');

        $this->getJson("/api/admin/geofence/{$id}", $this->auth())->assertStatus(403);
    }

    public function test_show_global_geofence_is_forbidden_for_prodi_admin(): void
    {
        $id = Geofence::where('nama', 'GF Global')->value('id');

        $this->getJson("/api/admin/geofence/{$id}", $this->auth())->assertStatus(403);
    }

    public function test_update_other_prodi_geofence_is_forbidden(): void
    {
        $id = Geofence::where('nama', 'GF TE')->value('id');

        $this->putJson("/api/admin/geofence/{$id}", ['nama' => 'Dibajak'], $this->auth())
            ->assertStatus(403);
        $this->assertSame('GF TE', Geofence::find($id)->nama);
    }

    public function test_delete_other_prodi_geofence_is_forbidden(): void
    {
        $id = Geofence::where('nama', 'GF TE')->value('id');

        $this->deleteJson("/api/admin/geofence/{$id}", [], $this->auth())->assertStatus(403);
        $this->assertDatabaseHas('geofences', ['id' => $id, 'nama' => 'GF TE']);
    }

    public function test_cannot_assign_geofence_to_other_prodi(): void
    {
        $this->postJson('/api/admin/geofence', [
            'nama' => 'GF bajokan',
            'latitude' => 0,
            'longitude' => 0,
            'radius' => 50,
            'prodi_id' => $this->prodiTe,
        ], $this->auth())->assertStatus(403);
        $this->assertDatabaseMissing('geofences', ['nama' => 'GF bajokan']);
    }

    public function test_cannot_move_own_geofence_to_other_prodi_or_global(): void
    {
        $id = Geofence::where('nama', 'GF TI')->value('id');

        $this->putJson("/api/admin/geofence/{$id}", ['prodi_id' => $this->prodiTe], $this->auth())
            ->assertStatus(403);
        $this->putJson("/api/admin/geofence/{$id}", ['prodi_id' => null], $this->auth())
            ->assertStatus(403);
        $this->assertSame($this->prodiTi, Geofence::find($id)->prodi_id);
    }

    public function test_geofence_used_by_jadwal_cannot_be_deleted(): void
    {
        $geofence = Geofence::where('nama', 'GF TI')->first();

        $year = TahunAjaran::create([
            'kode' => '2026-GF', 'nama' => '2026/2027', 'tanggal_mulai' => '2026-01-01',
            'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);
        $semester = Semester::create([
            'tahun_ajaran_id' => $year->id, 'kode' => '2026-GF-G', 'nama' => 'Ganjil',
            'tanggal_mulai' => '2026-01-01', 'tanggal_selesai' => '2026-12-31', 'status' => 'aktif',
        ]);

        $mk = MataKuliah::create([
            'kode_mk' => 'GFT101', 'nama' => 'MK Geofence', 'sks' => 2,
            'semester_id' => $semester->id,
            'prodi_id' => $this->prodiTi, 'total_pertemuan' => 16, 'status' => 'aktif',
        ]);
        Jadwal::create([
            'mata_kuliah_id' => $mk->id, 'kelas_id' => null, 'dosen_id' => null,
            'geofence_id' => $geofence->id, 'hari' => 'Senin', 'jam_mulai' => '08:00:00',
            'jam_selesai' => '09:30:00', 'durasi_menit' => 90, 'status' => 'aktif',
        ]);

        $this->deleteJson("/api/admin/geofence/{$geofence->id}", [], $this->auth())
            ->assertStatus(422);
        $this->assertDatabaseHas('geofences', ['id' => $geofence->id]);
    }

    public function test_super_admin_still_sees_all_geofences(): void
    {
        $admin = User::factory()->create([
            'email' => 'geo-root@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'not_required',
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first()->id);

        $token = $this->postJson('/api/auth/login', [
            'login' => 'geo-root@test.com',
            'password' => '12345678',
        ])->json('data.token');

        $names = collect($this->getJson('/api/admin/geofence', ['Authorization' => "Bearer {$token}"])->json('data'))
            ->pluck('nama');

        $this->assertCount(3, $names->unique());
    }
}
