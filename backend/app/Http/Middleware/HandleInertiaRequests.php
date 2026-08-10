<?php

namespace App\Http\Middleware;

use App\Models\Notification;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template loaded on the first page visit.
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default to every page.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'nama' => $user->nama,
                    'email' => $user->email,
                    'foto_profil' => $user->foto_profil,
                    'roles' => $user->roles->pluck('name'),
                    'role_label' => $user->roles->first()?->display_name,
                    'prodi' => $user->prodi?->only(['id', 'kode', 'nama']),
                ] : null,
            ],
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'info' => fn () => $request->session()->get('info'),
            ],
            'app' => [
                'name' => config('app.name', 'Absensi Mahasiswa'),
            ],
            'webpush' => [
                'vapid_public_key' => config('services.webpush.public_key'),
            ],

            'notifications' => [
                'unread' => fn () => $user
                    ? Notification::where('user_id', $user->id)->where('is_read', false)->count()
                    : 0,
            ],
        ]);

    }
}
