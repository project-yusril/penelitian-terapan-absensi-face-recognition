<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class AdminCrudTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $admin = User::factory()->create([
            'email' => 'admin@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first()->id);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'admin@test.com',
            'password' => '12345678',
        ]);
        $this->token = $response->json('data.token');
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_list_users(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/admin/users')
            ->assertStatus(200);
    }

    public function test_create_user(): void
    {
        $this->withHeaders($this->auth())
            ->postJson('/api/admin/users', [
                'nama' => 'Test User',
                'email' => 'newuser@test.com',
                'password' => 'password12345',
                'password_confirmation' => 'password12345',
                'roles' => ['mahasiswa'],
            ])
            ->assertStatus(201);
    }

    public function test_show_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('12345678')]);
        $this->withHeaders($this->auth())
            ->getJson("/api/admin/users/{$user->id}")
            ->assertStatus(200);
    }

    public function test_update_user(): void
    {
        $user = User::factory()->create(['password' => Hash::make('12345678')]);
        $this->withHeaders($this->auth())
            ->putJson("/api/admin/users/{$user->id}", [
                'nama' => 'Updated Name',
            ])
            ->assertStatus(200);
    }

    public function test_tahun_ajaran_crud(): void
    {
        $response = $this->withHeaders($this->auth())
            ->postJson('/api/admin/tahun-ajaran', [
                'nama' => '2025/2026',
                'kode' => '2025-1',
                'tanggal_mulai' => '2025-09-01',
                'tanggal_selesai' => '2026-07-31',
                'is_active' => true,
            ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $this->withHeaders($this->auth())->getJson('/api/admin/tahun-ajaran')->assertStatus(200);
        $this->withHeaders($this->auth())->getJson("/api/admin/tahun-ajaran/{$id}")->assertStatus(200);
        $this->withHeaders($this->auth())->putJson("/api/admin/tahun-ajaran/{$id}", ['nama' => '2025/2026 Genap'])->assertStatus(200);
        $this->withHeaders($this->auth())->deleteJson("/api/admin/tahun-ajaran/{$id}")->assertStatus(200);
    }

    public function test_geofence_crud(): void
    {
        $response = $this->withHeaders($this->auth())
            ->postJson('/api/admin/geofence', [
                'nama' => 'Gedung A',
                'latitude' => -6.2088,
                'longitude' => 106.8456,
                'radius' => 100,
                'is_active' => true,
            ]);
        $response->assertStatus(201);
        $id = $response->json('data.id');

        $this->withHeaders($this->auth())->getJson('/api/admin/geofence')->assertStatus(200);
        $this->withHeaders($this->auth())->putJson("/api/admin/geofence/{$id}", ['radius' => 150])->assertStatus(200);
        $this->withHeaders($this->auth())->deleteJson("/api/admin/geofence/{$id}")->assertStatus(200);
    }

    public function test_admin_dashboard(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/admin/dashboard')->assertStatus(200);
    }

    public function test_sp_records_list(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/admin/sp-records')->assertStatus(200);
    }

    public function test_settings(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/admin/settings')->assertStatus(200);
    }

    public function test_audit_trail(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/admin/audit-trail')->assertStatus(200);
    }
}
