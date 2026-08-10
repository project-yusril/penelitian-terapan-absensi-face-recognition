<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class NotificationTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();

        $user = User::factory()->create([
            'email' => 'notif@test.com',
            'password' => Hash::make('12345678'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'notif@test.com',
            'password' => '12345678',
        ]);
        $this->token = $response->json('data.token');
    }

    private function auth(): array
    {
        return ['Authorization' => "Bearer {$this->token}"];
    }

    public function test_list_notifications(): void
    {
        $this->withHeaders($this->auth())->getJson('/api/notifications')->assertStatus(200);
    }

    public function test_unread_count(): void
    {
        $this->withHeaders($this->auth())
            ->getJson('/api/notifications/unread-count')
            ->assertStatus(200);
    }

    public function test_mark_all_as_read(): void
    {
        $this->withHeaders($this->auth())->putJson('/api/notifications/read-all')->assertStatus(200);
    }

    public function test_update_fcm_token(): void
    {
        $this->withHeaders($this->auth())
            ->postJson('/api/fcm-token', ['fcm_token' => 'test-fcm-token-123'])
            ->assertStatus(200);
    }
}
