<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    public function test_health_endpoint(): void
    {
        $response = $this->getJson('/api/health');
        $response->assertStatus(200)
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_login_with_email(): void
    {
        $this->seedEssentialData();
        $user = User::factory()->create([
            'email' => 'test@test.com',
            'password' => Hash::make('password123'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'test@test.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['token', 'user']]);
    }

    public function test_login_with_invalid_credentials(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'login' => 'nonexistent@test.com',
            'password' => 'wrong',
        ]);
        $response->assertStatus(422);
    }

    public function test_login_with_inactive_account(): void
    {
        $this->seedEssentialData();
        $user = User::factory()->create([
            'email' => 'inactive@test.com',
            'password' => Hash::make('password123'),
            'status' => 'nonaktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);

        $response = $this->postJson('/api/auth/login', [
            'login' => 'inactive@test.com',
            'password' => 'password123',
        ]);
        $response->assertStatus(403);
    }

    public function test_get_current_user(): void
    {
        $this->seedEssentialData();
        $user = User::factory()->create([
            'email' => 'me@test.com',
            'password' => Hash::make('password123'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me');
        $response->assertStatus(200)
            ->assertJsonPath('data.email', 'me@test.com');
    }

    public function test_existing_token_is_rejected_after_account_deactivation(): void
    {
        $this->seedEssentialData();
        $user = User::factory()->create(['status' => 'aktif']);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);
        $token = $user->createToken('test')->plainTextToken;
        $user->update(['status' => 'nonaktif']);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/auth/me')
            ->assertForbidden();
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
    }

    public function test_unauthenticated_access_rejected(): void
    {
        $response = $this->getJson('/api/auth/me');
        $response->assertStatus(401);
    }

    public function test_logout(): void
    {
        $this->seedEssentialData();
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
            'fcm_token' => 'device-token-to-revoke',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');
        $response->assertStatus(200);
        $this->assertNull($user->fresh()->fcm_token);
    }

    public function test_change_password(): void
    {
        $this->seedEssentialData();
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
            'status' => 'aktif',
            'enrollment_status' => 'belum',
        ]);
        $user->roles()->attach(Role::where('name', 'mahasiswa')->first()->id);
        $token = $user->createToken('test')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/change-password', [
                'current_password' => 'password123',
                'new_password' => 'newpassword123',
                'new_password_confirmation' => 'newpassword123',
            ]);
        $response->assertStatus(200);
    }

    public function test_forgot_password_never_exposes_token_and_does_not_enumerate_email(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'reset@test.com']);

        $knownEmailResponse = $this->postJson('/api/auth/forgot-password', [
            'email' => $user->email,
        ]);
        $unknownEmailResponse = $this->postJson('/api/auth/forgot-password', [
            'email' => 'unknown@test.com',
        ]);

        $knownEmailResponse->assertOk();
        $unknownEmailResponse->assertOk();
        $this->assertSame($knownEmailResponse->json(), $unknownEmailResponse->json());
        $this->assertStringNotContainsStringIgnoringCase('token', $knownEmailResponse->getContent());
        $this->assertStringNotContainsStringIgnoringCase('token', $unknownEmailResponse->getContent());
        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_forgot_password_keeps_generic_response_when_delivery_fails(): void
    {
        Password::shouldReceive('sendResetLink')
            ->once()
            ->andThrow(new \RuntimeException('Mail transport failed'));

        $response = $this->postJson('/api/auth/forgot-password', [
            'email' => 'registered@test.com',
        ]);

        $response->assertOk()->assertExactJson([
            'success' => true,
            'message' => 'Jika email terdaftar, instruksi reset password telah dikirim.',
        ]);
    }

    public function test_password_reset_is_single_use_and_revokes_existing_credentials(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@test.com',
            'password' => Hash::make('password123'),
        ]);
        $user->createToken('phone');
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            'id' => 'existing-web-session',
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'PHPUnit',
            'payload' => 'test',
            'last_activity' => now()->timestamp,
        ]);
        $token = Password::createToken($user);

        $payload = [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ];

        $this->postJson('/api/auth/reset-password', $payload)->assertOk();

        $this->assertTrue(Hash::check('newpassword123', $user->fresh()->password));
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id]);
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
    }

    public function test_expired_password_reset_token_is_rejected(): void
    {
        $user = User::factory()->create(['email' => 'expired@test.com']);
        $token = Password::createToken($user);
        $broker = config('auth.defaults.passwords');
        $this->travel((int) config("auth.passwords.{$broker}.expire") + 1)->minutes();

        $this->postJson('/api/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertUnprocessable();
    }
}
