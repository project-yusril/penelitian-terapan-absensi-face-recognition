<?php

namespace Tests\Unit;

use App\Http\Middleware\RequireTrustedBiometricEvidence;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TrustedBiometricEvidenceGateTest extends TestCase
{
    #[Test]
    public function client_claims_are_rejected_when_compatibility_is_disabled(): void
    {
        config(['biometric.allow_client_claims' => false]);
        $middleware = new RequireTrustedBiometricEvidence;

        $response = $middleware->handle(Request::create('/api/mahasiswa/attendance/check-in', 'POST'), fn () => response()->json(['ok' => true]));

        $this->assertSame(503, $response->getStatusCode());
        $this->assertSame('TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED', $response->getData(true)['code']);
        $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    #[Test]
    public function legacy_client_claims_require_an_explicit_compatibility_switch(): void
    {
        config(['biometric.allow_client_claims' => true]);
        $middleware = new RequireTrustedBiometricEvidence;

        $response = $middleware->handle(Request::create('/api/mahasiswa/attendance/check-in', 'POST'), fn () => response()->json(['ok' => true]));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($response->getData(true)['ok']);
    }

    #[Test]
    public function production_rejects_client_claims_even_if_the_compatibility_switch_is_set(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['biometric.allow_client_claims' => true]);

        try {
            $middleware = new RequireTrustedBiometricEvidence;
            $response = $middleware->handle(Request::create('/api/mahasiswa/enrollment', 'POST'), fn () => response()->json(['ok' => true]));

            $this->assertSame(503, $response->getStatusCode());
            $this->assertSame('TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED', $response->getData(true)['code']);
        } finally {
            $this->app->detectEnvironment(fn () => 'testing');
        }
    }
}
