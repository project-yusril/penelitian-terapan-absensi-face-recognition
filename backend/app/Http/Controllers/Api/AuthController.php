<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\PrivateFileUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Login user and create token
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
            'device_name' => 'nullable|string',
        ]);

        // Support login via email or NIM
        $loginField = filter_var($request->login, FILTER_VALIDATE_EMAIL) ? 'email' : 'nim';

        $user = User::where($loginField, $request->login)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'login' => ['Kredensial yang diberikan tidak valid.'],
            ]);
        }

        if ($user->status !== 'aktif') {
            return $this->error('Akun Anda tidak aktif. Hubungi admin.', 403);
        }

        // M-04: Single-device policy hanya untuk token mobile (jangan putuskan sesi web/admin).
        // Token mobile diidentifikasi via name yang diawali "mobile-".
        $user->tokens()->where('name', 'like', 'mobile-%')->delete();

        $deviceName = 'mobile-'.($request->device_name ?: 'app');
        $token = $user->createToken($deviceName);

        // Update last login
        $user->update(['last_login_at' => now()]);

        // Load relationships
        $user->load('roles', 'prodi');

        return $this->success([
            'user' => [
                'id' => $user->id,
                'nama' => $user->nama,
                'email' => $user->email,
                'nim' => $user->nim,
                'nidn' => $user->nidn,
                'foto_profil' => $user->foto_profil,
                'foto_enrollment_url' => $this->enrollmentPhotoUrl($user),
                'roles' => $user->roles->pluck('name'),
                'prodi' => $user->prodi?->only(['id', 'kode', 'nama']),
                'must_change_password' => $user->must_change_password,
                'enrollment_status' => $user->enrollment_status,
            ],
            'token' => $token->plainTextToken,

            'token_type' => 'Bearer',
        ], 'Login berhasil');
    }

    /**
     * Logout user (revoke current token)
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return $this->success(message: 'Logout berhasil');
    }

    /**
     * Get authenticated user info
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles', 'prodi');

        return $this->success([
            'id' => $user->id,
            'nama' => $user->nama,
            'email' => $user->email,
            'nim' => $user->nim,
            'nidn' => $user->nidn,
            'nip' => $user->nip,
            'no_hp' => $user->no_hp,
            'jenis_kelamin' => $user->jenis_kelamin,
            'foto_profil' => $user->foto_profil,
            'foto_enrollment_url' => $this->enrollmentPhotoUrl($user),
            'roles' => $user->roles->pluck('name'),
            'prodi' => $user->prodi?->only(['id', 'kode', 'nama']),
            'kelas' => $user->kelas,
            'angkatan' => $user->angkatan,
            'semester' => $user->semester,
            'must_change_password' => $user->must_change_password,
            'enrollment_status' => $user->enrollment_status,
        ]);
    }

    /**
     * Bangun signed URL foto enrollment (disk privat) bila ada.
     * Berlaku 7 hari agar avatar di app tetap tampil tanpa sering re-fetch.
     */
    private function enrollmentPhotoUrl(User $user): ?string
    {
        if (! $user->foto_enrollment) {
            return null;
        }

        return app(PrivateFileUrlService::class)->enrollmentPhoto($user);
    }

    /**
     * Change password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:8|confirmed',
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak sesuai.'],
            ]);
        }

        $user->update([
            'password' => Hash::make($request->new_password),
            'must_change_password' => false,
        ]);

        return $this->success(message: 'Password berhasil diubah');
    }

    /**
     * Refresh token
     */
    public function refresh(Request $request): JsonResponse
    {
        $user = $request->user();

        // M-03: pertahankan nama device asli saat refresh
        $current = $user->currentAccessToken();
        $deviceName = $current?->name ?: 'mobile-app';

        $current?->delete();
        $newToken = $user->createToken($deviceName);

        return $this->success([
            'token' => $newToken->plainTextToken,
            'token_type' => 'Bearer',
        ], 'Token refreshed');
    }

    /**
     * Update FCM token
     */
    public function updateFcmToken(Request $request): JsonResponse
    {
        $request->validate([
            'fcm_token' => 'required|string',
        ]);

        $request->user()->update([
            'fcm_token' => $request->fcm_token,
        ]);

        return $this->success(message: 'FCM token updated');
    }
}
