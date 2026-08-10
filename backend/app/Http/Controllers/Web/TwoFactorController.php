<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Session;
use Inertia\Inertia;
use Inertia\Response;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-Factor Authentication (TOTP, RFC 6238) untuk akun super_admin.
 * Opsional — pengguna dapat mengaktifkan/menonaktifkan dari halaman Profil.
 *
 * Alur:
 *  1. GET  /profile/2fa           — tampilkan status & QR (jika belum confirm).
 *  2. POST /profile/2fa/setup     — generate secret baru + simpan terenkripsi.
 *  3. POST /profile/2fa/confirm   — verifikasi 6 digit OTP → aktifkan.
 *  4. POST /profile/2fa/disable   — matikan & hapus secret.
 *
 * Saat login, middleware `2fa.required` akan menolak permintaan jika user
 * sudah punya 2FA aktif tapi belum verifikasi OTP di sesi ini.
 */
class TwoFactorController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $enabled = ! is_null($user->two_factor_confirmed_at);

        $qrSvg = null;
        $secret = null;

        // Bila belum aktif tapi punya secret pending (dari setup), tampilkan QR.
        if (! $enabled && $user->two_factor_secret) {
            $secret = Crypt::decryptString($user->two_factor_secret);
            $qrSvg = $this->qrSvg($user->email, $secret);
        }

        return Inertia::render('Profile/TwoFactor', [
            'enabled' => $enabled,
            'has_pending_secret' => ! $enabled && ! is_null($user->two_factor_secret),
            'qr_svg' => $qrSvg,
            'secret' => $secret,
        ]);
    }

    public function setup(Request $request): RedirectResponse
    {
        $user = $request->user();
        $google2fa = new Google2FA;
        $secret = $google2fa->generateSecretKey(32);

        $user->update([
            'two_factor_secret' => Crypt::encryptString($secret),
            'two_factor_confirmed_at' => null,
        ]);

        return redirect()->route('profile.2fa')->with('success', 'Secret 2FA dibuat. Scan QR di bawah lalu masukkan kode 6 digit untuk mengaktifkan.');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        $user = $request->user();
        if (! $user->two_factor_secret) {
            return back()->withErrors(['code' => 'Belum ada secret 2FA. Klik "Generate" terlebih dahulu.']);
        }

        $secret = Crypt::decryptString($user->two_factor_secret);
        $google2fa = new Google2FA;
        $valid = $google2fa->verifyKey($secret, $request->code, 1);

        if (! $valid) {
            return back()->withErrors(['code' => 'Kode tidak valid. Pastikan jam perangkat sinkron dan coba kode terbaru.']);
        }

        $user->update(['two_factor_confirmed_at' => now()]);
        Session::put('2fa_passed', true);

        AuditTrail::create([
            'user_id' => $user->id,
            'action' => '2fa_enabled',
            'model_type' => 'User',
            'model_id' => $user->id,
            'old_values' => null,
            'new_values' => ['enabled_at' => now()->toDateTimeString()],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('profile.2fa')->with('success', '2FA berhasil diaktifkan. Mulai login berikutnya, kode 6 digit akan diminta.');
    }

    public function disable(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required'],
        ]);

        $user = $request->user();
        if (! \Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Password lama tidak cocok.']);
        }

        $user->update([
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
        ]);
        Session::forget('2fa_passed');

        AuditTrail::create([
            'user_id' => $user->id,
            'action' => '2fa_disabled',
            'model_type' => 'User',
            'model_id' => $user->id,
            'old_values' => null,
            'new_values' => ['disabled_at' => now()->toDateTimeString()],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return redirect()->route('profile.2fa')->with('success', '2FA dinonaktifkan.');
    }

    /**
     * Halaman challenge OTP saat login (jika user punya 2FA aktif & belum verify).
     */
    public function challenge(): Response
    {
        return Inertia::render('Auth/TwoFactorChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        $request->validate(['code' => ['required', 'digits:6']]);

        $user = $request->user();
        if (! $user->two_factor_secret) {
            Session::put('2fa_passed', true);

            return redirect()->intended(route('dashboard'));
        }

        $secret = Crypt::decryptString($user->two_factor_secret);
        $google2fa = new Google2FA;
        $valid = $google2fa->verifyKey($secret, $request->code, 1);

        if (! $valid) {
            return back()->withErrors(['code' => 'Kode tidak valid.']);
        }

        Session::put('2fa_passed', true);

        return redirect()->intended(route('dashboard'));
    }

    /**
     * Render QR otpauth URL ke SVG (tidak butuh GD/Imagick).
     */
    private function qrSvg(string $email, string $secret): string
    {
        $issuer = rawurlencode(config('app.name', 'Absensi'));
        $label = rawurlencode("{$issuer}:{$email}");
        $otpauth = "otpauth://totp/{$label}?secret={$secret}&issuer={$issuer}";

        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd);

        return (new Writer($renderer))->writeString($otpauth);
    }
}
