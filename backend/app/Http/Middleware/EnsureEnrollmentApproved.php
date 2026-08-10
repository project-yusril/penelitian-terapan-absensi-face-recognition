<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEnrollmentApproved
{
    /**
     * Handle an incoming request.
     * Ensures the authenticated user has an approved face enrollment before accessing attendance endpoints.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        if ($user->enrollment_status !== 'approved' || ! $user->faceEmbeddings()->where('status', 'approved')->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Enrollment wajah belum disetujui. Silakan lakukan enrollment terlebih dahulu.',
                'error' => [
                    'code' => 'ENROLLMENT_NOT_APPROVED',
                    'enrollment_status' => $user->enrollment_status,
                ],
            ], 403);
        }

        return $next($request);
    }
}
