<?php

namespace Tests\Feature;

use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class AttendanceFlowTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $prodi = Prodi::where('kode', 'TI')->first();

        // Mahasiswa
        $mhs = User::factory()->create([
            'email' => 'mhs_att@test.com',
            'nim' => '2024001099',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'approved',
            'prodi_id' => $prodi->id,
        ]);
        $mhs->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        // Dosen
        $dosen = User::factory()->create([
            'email' => 'dosen_att@test.com',
            'nidn' => '9999999999',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
            'prodi_id' => $prodi->id,
        ]);
        $dosen->roles()->attach(Role::where('name', 'dosen')->first()->id);

        // Admin
        $admin = User::factory()->create([
            'email' => 'admin_att@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $admin->roles()->attach(Role::where('name', 'super_admin')->first()->id);
        $this->adminToken = $this->postJson('/api/auth/login', [
            'login' => 'admin_att@test.com', 'password' => '12345678',
        ])->json('data.token');
    }

    public function test_mahasiswa_attendance_today(): void
    {
        $mhs = User::where('email', 'mhs_att@test.com')->first();
        $token = $mhs->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mahasiswa/attendance/today')
            ->assertStatus(200);
    }

    public function test_mahasiswa_attendance_history(): void
    {
        $mhs = User::where('email', 'mhs_att@test.com')->first();
        $token = $mhs->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mahasiswa/attendance/history')
            ->assertStatus(200);
    }

    public function test_dosen_class_today(): void
    {
        $dosen = User::where('email', 'dosen_att@test.com')->first();
        $token = $dosen->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/dosen/attendance/class-today')
            ->assertStatus(200);
    }

    public function test_dosen_attendance_list(): void
    {
        $dosen = User::where('email', 'dosen_att@test.com')->first();
        $token = $dosen->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/dosen/attendance')
            ->assertStatus(200);
    }

    public function test_dosen_mata_kuliah(): void
    {
        $dosen = User::where('email', 'dosen_att@test.com')->first();
        $token = $dosen->createToken('test')->plainTextToken;

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/dosen/mata-kuliah')
            ->assertStatus(200);
    }

    public function test_admin_reports(): void
    {
        $mhs = User::where('email', 'mhs_att@test.com')->first();
        $this->withHeader('Authorization', "Bearer {$this->adminToken}")
            ->getJson('/api/admin/reports/by-mahasiswa?user_id='.$mhs->id)
            ->assertStatus(200);
    }
}
