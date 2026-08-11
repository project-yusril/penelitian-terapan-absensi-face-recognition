<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression M-23: limiter `api` sebelumnya terdefinisi di
 * `AppServiceProvider` tetapi tidak pernah dipasang, sehingga endpoint
 * terautentikasi — termasuk `POST /auth/change-password` dan
 * `POST /auth/refresh` — tidak berbatas.
 */
class ApiRateLimitTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private string $token;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $this->user = User::factory()->create([
            'email' => 'limiter@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'not_required',
        ]);
        $this->user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        $this->token = $this->postJson('/api/auth/login', [
            'login' => 'limiter@test.com',
            'password' => '12345678',
        ])->json('data.token');

        $this->assertNotNull($this->token, 'Login harus menghasilkan token.');
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_change_password_is_rate_limited(): void
    {
        // Limiter `auth-sensitive`: 5 percobaan per menit per user. Password
        // lama sengaja salah — inilah bentuk brute force yang dicegah.
        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($this->auth())
                ->postJson('/api/auth/change-password', [
                    'current_password' => "tebakan-salah-{$i}",
                    'new_password' => 'password-baru-123',
                    'new_password_confirmation' => 'password-baru-123',
                ])
                ->assertStatus(422);
        }

        $this->withHeaders($this->auth())
            ->postJson('/api/auth/change-password', [
                'current_password' => 'tebakan-salah-6',
                'new_password' => 'password-baru-123',
                'new_password_confirmation' => 'password-baru-123',
            ])
            ->assertStatus(429);
    }

    public function test_change_password_limit_is_stricter_than_general_api_limit(): void
    {
        // Endpoint sensitif harus berhenti jauh sebelum kuota API umum (60).
        $blockedAt = null;

        for ($i = 0; $i < 20; $i++) {
            $status = $this->withHeaders($this->auth())
                ->postJson('/api/auth/change-password', [
                    'current_password' => 'tebakan-salah',
                    'new_password' => 'password-baru-123',
                    'new_password_confirmation' => 'password-baru-123',
                ])->getStatusCode();

            if ($status === 429) {
                $blockedAt = $i;
                break;
            }
        }

        $this->assertNotNull($blockedAt, 'Change-password harus mencapai 429.');
        $this->assertLessThan(60, $blockedAt);
    }

    public function test_authenticated_endpoint_is_rate_limited(): void
    {
        // Limiter `api`: 60 request per menit per user. Sebelum M-23 tidak ada
        // batas sama sekali pada group terautentikasi.
        $blocked = false;

        for ($i = 0; $i < 65; $i++) {
            $status = $this->withHeaders($this->auth())
                ->postJson('/api/auth/refresh')
                ->getStatusCode();

            if ($status === 429) {
                $blocked = true;
                break;
            }

            // Refresh mencabut token lama dan menerbitkan yang baru.
            $refreshed = $this->withHeaders($this->auth())->postJson('/api/auth/refresh');
            if ($refreshed->getStatusCode() === 429) {
                $blocked = true;
                break;
            }
            $this->token = $refreshed->json('data.token') ?? $this->token;
        }

        $this->assertTrue($blocked, 'Endpoint terautentikasi harus mencapai 429.');
    }

    public function test_limit_is_keyed_per_user_not_per_ip(): void
    {
        // Penting untuk NAT kampus: satu user yang kena limit tidak boleh
        // mengunci user lain dari IP yang sama.
        for ($i = 0; $i < 6; $i++) {
            $this->withHeaders($this->auth())
                ->postJson('/api/auth/change-password', [
                    'current_password' => 'tebakan-salah',
                    'new_password' => 'password-baru-123',
                    'new_password_confirmation' => 'password-baru-123',
                ]);
        }

        // Test HTTP client memakai ulang container yang sama antar request,
        // sehingga guard memoize user dari request sebelumnya dan token user
        // kedua akan tetap teresolusi sebagai user pertama. Production tidak
        // punya masalah ini karena setiap request mendapat container baru;
        // `forgetGuards()` menirukan batas request tersebut.
        $this->app['auth']->forgetGuards();

        $other = User::factory()->create([
            'email' => 'limiter-lain@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'not_required',
        ]);
        $other->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        $otherToken = $this->postJson('/api/auth/login', [
            'login' => 'limiter-lain@test.com',
            'password' => '12345678',
        ])->json('data.token');

        $this->withHeaders(['Authorization' => "Bearer {$otherToken}"])
            ->postJson('/api/auth/change-password', [
                'current_password' => '12345678',
                'new_password' => 'password-baru-123',
                'new_password_confirmation' => 'password-baru-123',
            ])
            ->assertStatus(200);
    }
}
