<?php

namespace Tests\Feature;

use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class SpManagementTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private string $adminToken;

    private string $kaprodiToken;

    private string $kajurToken;

    private string $mahasiswaToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $prodi = Prodi::where('kode', 'TI')->first();

        // Admin
        $admin = User::factory()->create([
            'email' => 'sp_admin@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first()->id);
        $this->adminToken = $this->postJson('/api/auth/login', [
            'login' => 'sp_admin@test.com', 'password' => '12345678',
        ])->json('data.token');

        // Kaprodi
        $kaprodi = User::factory()->create([
            'email' => 'sp_kaprodi@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
            'prodi_id' => $prodi->id,
        ]);
        $kaprodi->roles()->attach(Role::where('name', 'kaprodi')->first()->id);
        $this->kaprodiToken = $this->postJson('/api/auth/login', [
            'login' => 'sp_kaprodi@test.com', 'password' => '12345678',
        ])->json('data.token');

        // Kajur
        $kajur = User::factory()->create([
            'email' => 'sp_kajur@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $kajur->roles()->attach(Role::where('name', 'ketua_jurusan')->first()->id);
        $this->kajurToken = $this->postJson('/api/auth/login', [
            'login' => 'sp_kajur@test.com', 'password' => '12345678',
        ])->json('data.token');

        // Mahasiswa
        $mhs = User::factory()->create([
            'email' => 'sp_mhs@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
            'prodi_id' => $prodi->id,
        ]);
        $mhs->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);
        $this->mahasiswaToken = $this->postJson('/api/auth/login', [
            'login' => 'sp_mhs@test.com', 'password' => '12345678',
        ])->json('data.token');
    }

    public function test_admin_sp_records(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/sp-records')->assertStatus(200);
    }

    public function test_kaprodi_sp_records(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->kaprodiToken}")
            ->getJson('/api/kaprodi/sp-records')->assertStatus(200);
    }

    public function test_kajur_sp_records(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->kajurToken}")
            ->getJson('/api/kajur/sp-records')->assertStatus(200);
    }

    public function test_mahasiswa_sp_records(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->mahasiswaToken}")
            ->getJson('/api/mahasiswa/sp-records')->assertStatus(200);
    }

    public function test_kaprodi_leave_requests(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->kaprodiToken}")
            ->getJson('/api/kaprodi/leave-requests')->assertStatus(200);
    }

    public function test_kaprodi_enrollments(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->kaprodiToken}")
            ->getJson('/api/kaprodi/enrollments')->assertStatus(200);
    }
}
