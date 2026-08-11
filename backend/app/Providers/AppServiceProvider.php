<?php

namespace App\Providers;

use App\Listeners\SendNotificationListener;
use App\Models\User;
use App\Observers\UserObserver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->hardenProductionSession();
    }

    /**
     * M-21: session cookie fail-closed di production.
     *
     * Default `config/session.php` bergantung pada env var (SESSION_SECURE_COOKIE
     * null => fail-open). Di production kita paksa cookie Secure + HttpOnly dan
     * SameSite minimal `lax`, kecuali operator secara eksplisit meng-override
     * lewat env. Dijalankan di register() agar berlaku sebelum StartSession boot.
     */
    private function hardenProductionSession(): void
    {
        if (! $this->app->environment('production')) {
            return;
        }

        // Secure: hanya kirim cookie via HTTPS. Fail-closed bila env tak diset.
        if (env('SESSION_SECURE_COOKIE') === null) {
            config(['session.secure' => true]);
        }

        // HttpOnly: cegah akses cookie dari JavaScript. Default true, tapi
        // pastikan operator tidak sengaja mematikannya di production.
        if (env('SESSION_HTTP_ONLY') === null) {
            config(['session.http_only' => true]);
        }

        // SameSite: minimal `lax` untuk mitigasi CSRF. Tolak nilai kosong/null
        // yang membuat cookie dikirim lintas situs tanpa batas.
        $sameSite = strtolower((string) config('session.same_site'));
        if (! in_array($sameSite, ['lax', 'strict', 'none'], true)) {
            config(['session.same_site' => 'lax']);
        }

        // SameSite=none wajib berpasangan dengan Secure agar diterima browser.
        if (config('session.same_site') === 'none') {
            config(['session.secure' => true]);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::subscribe(SendNotificationListener::class);
        User::observe(UserObserver::class);
        $this->configureRateLimiting();
    }

    /**
     * Configure API rate limiting
     */
    protected function configureRateLimiting(): void
    {
        // M-23: default API rate limit, 60 request per menit per user.
        //
        // Keying per user, bukan per IP. Seluruh mahasiswa di belakang NAT
        // kampus berbagi satu alamat, sehingga kuota per IP akan membuat satu
        // pengguna aktif mengunci pengguna lain — masalah yang sama sudah
        // dihindari pada limiter `login` (M-21). Fallback IP hanya untuk
        // request tanpa identitas.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->getAuthIdentifier() ?: $request->ip());
        });

        // M-23: mutasi credential pada sesi yang sudah login.
        //
        // `POST /auth/change-password` memverifikasi `current_password` dengan
        // `Hash::check`, sehingga token yang dicuri dapat dipakai menebak
        // password lama. Ancamannya per akun, bukan per IP, jadi keying cukup
        // per user dan tidak perlu batas IP yang bisa mengunci NAT kampus.
        RateLimiter::for('auth-sensitive', function (Request $request) {
            return Limit::perMinute(5)->by('auth-sensitive|'.($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });

        // Auth (login) rate limit.
        // Dibatasi per kombinasi IP+identitas dan per IP. Keying gabungan
        // mencegah brute force satu akun, sementara batas IP yang lebih longgar
        // menjaga pengguna sah di belakang NAT kampus tetap dapat login.
        RateLimiter::for('login', function (Request $request) {
            $identifier = strtolower((string) ($request->input('login') ?? $request->input('email') ?? ''));

            return [
                Limit::perMinute(5)->by($request->ip().'|'.$identifier),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        // Attendance rate limit: 10 requests per minute per user
        RateLimiter::for('attendance', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // File upload rate limit: 5 requests per minute per user
        RateLimiter::for('upload', function (Request $request) {
            return Limit::perMinute(5)->by($request->user()?->id ?: $request->ip());
        });

        // Export rate limit: 3 requests per minute per user
        RateLimiter::for('export', function (Request $request) {
            return Limit::perMinute(3)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('biometric-probe', function (Request $request) {
            $user = (string) $request->user()->getAuthIdentifier();
            $tooManyRequests = function (Request $request, array $headers) {
                $headers['Retry-After'] = (string) ($headers['Retry-After'] ?? 60);
                $headers['Cache-Control'] = 'private, no-store';

                $response = response()->json([
                    'code' => 'TOO_MANY_ATTEMPTS',
                    'message' => 'Terlalu banyak percobaan. Coba lagi nanti.',
                ], 429);
                $response->headers->replace($headers);

                return $response;
            };

            return [
                Limit::perMinute(config('biometric.probe_rate_limits.user_per_minute'))->by("biometric:user:minute:{$user}")->response($tooManyRequests),
                Limit::perHour(config('biometric.probe_rate_limits.user_per_hour'))->by("biometric:user:hour:{$user}")->response($tooManyRequests),
                Limit::perMinute(config('biometric.probe_rate_limits.ip_per_minute'))->by('biometric:ip:'.$request->ip())->response($tooManyRequests),
            ];
        });
    }
}
