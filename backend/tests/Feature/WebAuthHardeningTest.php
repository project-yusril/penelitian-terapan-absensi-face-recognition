<?php

namespace Tests\Feature;

use App\Models\Prodi;
use App\Models\Role;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\SeedsEssentialData;
use Tests\TestCase;

/**
 * Regression M-21: throttle login web dan revocation sesi lain saat
 * change password.
 */
class WebAuthHardeningTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedEssentialData();
    }

    private function dashboardUser(): User
    {
        $prodi = Prodi::where('kode', 'TI')->firstOrFail();
        $user = User::factory()->create([
            'email' => 'dash@test.com', 'nim' => null, 'prodi_id' => $prodi->id,
            'status' => 'aktif', 'enrollment_status' => 'not_required',
            'password' => Hash::make('oldpassword1'),
        ]);
        $user->roles()->attach(Role::where('name', 'super_admin')->value('id'));

        return $user;
    }

    public function test_web_login_is_throttled_after_five_attempts(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['login' => 'nobody@test.com', 'password' => 'wrong']);
        }

        $this->post('/login', ['login' => 'nobody@test.com', 'password' => 'wrong'])
            ->assertStatus(429);
    }

    public function test_change_password_revokes_other_sessions_and_tokens(): void
    {
        $user = $this->dashboardUser();
        $user->createToken('mobile')->plainTextToken;

        // Sesi lain milik user pada perangkat berbeda.
        DB::table('sessions')->insert([
            'id' => 'other-session-id',
            'user_id' => $user->id,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'other-device',
            'payload' => 'x',
            'last_activity' => now()->timestamp,
        ]);

        $this->assertSame(1, $user->tokens()->count());

        $this->actingAs($user)
            ->put('/profile/password', [
                'current_password' => 'oldpassword1',
                'password' => 'newpassword1',
                'password_confirmation' => 'newpassword1',
            ])
            ->assertRedirect();

        $this->assertSame(0, $user->tokens()->count());
        $this->assertDatabaseMissing('sessions', ['id' => 'other-session-id']);
    }

    /**
     * Jalankan ulang hardening provider seolah berada di environment tertentu
     * tanpa env cookie yang di-set operator (fail-open default).
     */
    private function bootSessionHardening(string $environment): void
    {
        config([
            'session.secure' => null,
            'session.http_only' => null,
            'session.same_site' => null,
        ]);

        $this->app['env'] = $environment;

        (new AppServiceProvider($this->app))->register();
    }

    public function test_production_session_is_secure_fail_closed(): void
    {
        $this->bootSessionHardening('production');

        $this->assertTrue(config('session.secure'), 'Cookie session wajib Secure di production.');
        $this->assertTrue(config('session.http_only'), 'Cookie session wajib HttpOnly di production.');
        $this->assertSame('lax', config('session.same_site'), 'SameSite wajib minimal lax di production.');
    }

    public function test_non_production_does_not_force_secure_cookie(): void
    {
        $this->bootSessionHardening('local');

        // Di luar production biarkan default env agar dev via HTTP tetap jalan.
        $this->assertNull(config('session.secure'));
    }

    public function test_production_same_site_none_forces_secure(): void
    {
        config([
            'session.secure' => null,
            'session.http_only' => null,
            'session.same_site' => 'none',
        ]);
        $this->app['env'] = 'production';

        (new AppServiceProvider($this->app))->register();

        $this->assertSame('none', config('session.same_site'));
        $this->assertTrue(config('session.secure'), 'SameSite=none wajib berpasangan dengan Secure.');
    }
}
