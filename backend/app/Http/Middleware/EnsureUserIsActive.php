<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->status === 'aktif') {
            return $next($request);
        }

        $request->user()?->tokens()->delete();
        if ($request->hasSession()) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        abort(403, 'Akun tidak aktif');
    }
}
