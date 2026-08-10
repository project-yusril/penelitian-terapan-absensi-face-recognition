<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Web (session) role guard for the admin dashboard.
 *
 * Unlike CheckRole (which returns JSON for the mobile API), this one
 * redirects unauthenticated users to the login page and aborts with 403
 * for authenticated users who lack the required role.
 */
class EnsureUserRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! empty($roles) && ! $user->hasAnyRole($roles)) {
            abort(403, 'Anda tidak memiliki akses ke halaman ini.');
        }

        return $next($request);
    }
}
