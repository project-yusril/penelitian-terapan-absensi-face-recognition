<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\ReadinessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\SeedsEssentialData;
use Tests\TestCase;

class HealthContractTest extends TestCase
{
    use RefreshDatabase, SeedsEssentialData;

    public function test_public_health_is_exact_and_does_not_resolve_readiness_dependencies(): void
    {
        $this->instance(ReadinessService::class, Mockery::mock(ReadinessService::class, function ($mock): void {
            $mock->shouldNotReceive('checks');
        }));

        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_requires_active_super_admin(): void
    {
        $this->seedEssentialData();
        $this->getJson('/api/healthz')->assertUnauthorized();

        $student = $this->user('mahasiswa');
        $this->actingAs($student)->getJson('/api/healthz')->assertForbidden();

        $inactive = $this->user('super_admin', 'nonaktif');
        $this->actingAs($inactive)->getJson('/api/healthz')->assertForbidden();
    }

    public function test_readiness_returns_only_generic_allowlisted_results(): void
    {
        $this->seedEssentialData();
        $operator = $this->user('super_admin');
        $this->instance(ReadinessService::class, Mockery::mock(ReadinessService::class, function ($mock): void {
            $mock->shouldReceive('checks')->once()->andReturn([
                'database' => 'ok',
                'cache' => 'unavailable',
                'storage' => 'ok',
            ]);
        }));

        $response = $this->actingAs($operator)->getJson('/api/healthz');
        $response->assertServiceUnavailable()->assertExactJson([
            'status' => 'not_ready',
            'checks' => ['database' => 'ok', 'cache' => 'unavailable', 'storage' => 'ok'],
        ]);
        foreach (['exception', 'path', 'class', 'environment', 'version', 'maintenance', 'timestamp'] as $detail) {
            $this->assertStringNotContainsString($detail, strtolower($response->getContent()));
        }
    }

    private function user(string $role, string $status = 'aktif'): User
    {
        $user = User::factory()->create(['status' => $status]);
        $user->roles()->attach(Role::where('name', $role)->value('id'));

        return $user;
    }
}
