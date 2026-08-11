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
     * are not authoritative. Keep legacy mode explicit and disabled by default,
     * especially when production configuration is incomplete.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! app()->isProduction() && config('biometric.allow_client_claims') === true) {
            return $next($request);
        }

        return new JsonResponse([
            'success' => false,
            'code' => 'TRUSTED_BIOMETRIC_EVIDENCE_REQUIRED',
            'message' => 'Verifikasi biometrik tepercaya belum tersedia.',
        ], 503, ['Cache-Control' => 'private, no-store']);
    }
}
