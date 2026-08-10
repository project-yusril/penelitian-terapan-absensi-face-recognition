<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class RateLimitingTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $user = User::factory()->create([
            'email' => 'ratelimit@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);
    }

    public function test_login_rate_limiting(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', [
                'login' => 'ratelimit@test.com',
                'password' => '12345678',
            ])->assertStatus(200);
        }

        $this->postJson('/api/auth/login', [
            'login' => 'ratelimit@test.com',
            'password' => '12345678',
        ])->assertStatus(429);
    }
}
