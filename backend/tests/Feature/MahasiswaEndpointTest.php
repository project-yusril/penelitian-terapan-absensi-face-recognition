<?php

namespace Tests\Feature;

use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class MahasiswaEndpointTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $prodi = Prodi::where('kode', 'TI')->first();
        $user = User::factory()->create([
            'email' => 'mhs@test.com',
            'nim' => '2024001001',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
            'prodi_id' => $prodi->id,
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'mhs@test.com',
            'password' => '12345678',
        ]);
        $this->token = $response->json('data.token');
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_dashboard(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/dashboard')->assertStatus(200);
    }

    public function test_attendance_today(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/attendance/today')->assertStatus(200);
    }

    public function test_attendance_history(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/attendance/history')->assertStatus(200);
    }

    public function test_enrollment_status(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/enrollment/status')->assertStatus(200);
    }

    public function test_sp_records(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/sp-records')->assertStatus(200);
    }

    public function test_leave_requests(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/leave-requests')->assertStatus(200);
    }

    public function test_jadwal(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/jadwal')->assertStatus(200);
    }

    public function test_jadwal_today(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/jadwal/today')->assertStatus(200);
    }

    public function test_jadwal_active(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/mahasiswa/jadwal/active')->assertStatus(200);
    }

    public function test_notifications(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/notifications')->assertStatus(200);
    }

    public function test_notifications_unread_count(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/notifications/unread-count')->assertStatus(200);
    }
}
