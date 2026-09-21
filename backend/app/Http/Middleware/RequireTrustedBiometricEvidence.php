<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireTrustedBiometricEvidence
{
    /**
     * Client-computed liveness, face distance, embedding, and location claims
     * are not authoritative. Since 21 Sep 2026 the client-attested flow is
     * explicitly enabled for the research demo via BIOMETRIC_ALLOW_CLIENT_CLAIMS
     * (see ADR-001 revision); the gate stays fail-closed until the flag is set.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('biometric.allow_client_claims') === true) {
            return $next($request);
        }

        return new JsonResponse([
            'success' => false,
            'code' => 'TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED',
            'message' => 'Verifikasi biometrik tepercaya belum tersedia.',
        ], 503, ['Cache-Control' => 'private, no-store']);
    }
}
