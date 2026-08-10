<?php

namespace App\Http\Controllers\Web\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Daftar role yang boleh masuk ke dashboard web admin.
     * Mahasiswa & orang tua memakai aplikasi mobile, bukan dashboard ini.
     */
    private const DASHBOARD_ROLES = [
        'super_admin',
        'ketua_jurusan',
        'admin_jurusan',
        'kaprodi',
        'admin_prodi',
        'dosen',
    ];

    public function show(): Response|RedirectResponse
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'login' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $loginField = filter_var($credentials['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'nim';

        /** @var User|null $user */
        $user = User::where($loginField, $credentials['login'])->first();

        if (! $user || ! Hash::check($credentials['password'], $user->password)) {
            throw ValidationException::withMessages([
                'login' => 'Kredensial yang diberikan tidak valid.',
            ]);
        }

        if ($user->status !== 'aktif') {
            throw ValidationException::withMessages([
                'login' => 'Akun Anda tidak aktif. Hubungi administrator.',
            ]);
        }

        if (! $user->hasAnyRole(self::DASHBOARD_ROLES)) {
            throw ValidationException::withMessages([
                'login' => 'Akun ini tidak memiliki akses ke dashboard admin.',
            ]);
        }

        Auth::login($user, $request->boolean('remember'));
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
