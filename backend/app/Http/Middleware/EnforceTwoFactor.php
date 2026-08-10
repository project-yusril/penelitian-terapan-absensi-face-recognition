<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bila user memiliki 2FA aktif (`two_factor_confirmed_at` terisi) tetapi
 * belum melewati challenge OTP di sesi ini, redirect ke halaman challenge.
 *
 * Jalur 2FA setup/disable & challenge sendiri di-bypass agar user bisa
 * masuk untuk verifikasi atau menonaktifkan 2FA dari halaman profile.
 */
class EnforceTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $user->two_factor_confirmed_at) {
            return $next($request);
        }
        if ($request->session()->get('2fa_passed') === true) {
            return $next($request);
        }

        // Bypass untuk endpoint terkait 2FA & logout supaya user tidak terkurung.
        $bypass = [
            'two-factor.challenge',
            'two-factor.verify',
            'profile.2fa',
            'profile.2fa.disable',
            'logout',
        ];
        if (in_array($request->route()?->getName(), $bypass, true)) {
            return $next($request);
        }

        return redirect()->route('two-factor.challenge');
    }
}
