<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class RoleAccessControlTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private array $tokens = [];

    private function createUser(string $role, string $email, array $extra = []): User
    {
        $user = User::factory()->create(array_merge([
            'email' => $email,
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ], $extra));
        $roleModel = Role::where('name', $role)->first();
        $user->roles()->attach($roleModel->id);

        return $user;
    }

    private function loginAs(string $email): string
    {
        if (isset($this->tokens[$email])) {
            return $this->tokens[$email];
        }
        $response = $this->postJson('/api/auth/login', [
            'login' => $email,
            'password' => '12345678',
        ]);
        $this->tokens[$email] = $response->json('data.token');

        return $this->tokens[$email];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    public function test_admin_can_access_admin_routes(): void
    {
        $this->createUser('super_admin', 'admin@test.com');
        $token = $this->loginAs('admin@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/dashboard')
            ->assertStatus(200);
    }

    public function test_mahasiswa_cannot_access_admin_routes(): void
    {
        $this->createUser('mahasiswa', 'mhs@test.com');
        $token = $this->loginAs('mhs@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/dashboard')
            ->assertStatus(403);
    }

    public function test_dosen_cannot_access_admin_routes(): void
    {
        $this->createUser('dosen', 'dosen@test.com');
        $token = $this->loginAs('dosen@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/admin/users')
            ->assertStatus(403);
    }

    public function test_mahasiswa_can_access_mahasiswa_routes(): void
    {
        $this->createUser('mahasiswa', 'mhs2@test.com');
        $token = $this->loginAs('mhs2@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mahasiswa/dashboard')
            ->assertStatus(200);
    }

    public function test_orang_tua_cannot_access_mahasiswa_routes(): void
    {
        $this->createUser('orang_tua', 'ortu@test.com');
        $token = $this->loginAs('ortu@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/mahasiswa/dashboard')
            ->assertStatus(403);
    }

    public function test_kaprodi_can_access_kaprodi_routes(): void
    {
        $this->createUser('kaprodi', 'kaprodi@test.com');
        $token = $this->loginAs('kaprodi@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/kaprodi/dashboard')
            ->assertStatus(200);
    }

    public function test_kajur_can_access_kajur_routes(): void
    {
        $this->createUser('ketua_jurusan', 'kajur@test.com');
        $token = $this->loginAs('kajur@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/kajur/dashboard')
            ->assertStatus(200);
    }

    public function test_dosen_can_access_dosen_routes(): void
    {
        $this->createUser('dosen', 'dosen2@test.com');
        $token = $this->loginAs('dosen2@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/dosen/dashboard')
            ->assertStatus(200);
    }

    public function test_orang_tua_can_access_orang_tua_routes(): void
    {
        $this->createUser('orang_tua', 'ortu2@test.com');
        $token = $this->loginAs('ortu2@test.com');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/orang-tua/dashboard')
            ->assertStatus(200);
    }
}
