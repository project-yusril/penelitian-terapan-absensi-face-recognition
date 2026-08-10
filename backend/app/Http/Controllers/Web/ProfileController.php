<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AuditTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Profil Saya — pengguna dashboard (admin/dosen/manajemen) dapat
 * memperbarui profil & mengganti password mereka sendiri tanpa harus
 * meminta admin lain. Sebelumnya hanya tersedia di mobile.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): Response
    {
        $user = $request->user()->load('roles:id,name,display_name', 'prodi:id,kode,nama');

        return Inertia::render('Profile/Edit', [
            'user' => [
                'id' => $user->id,
                'nama' => $user->nama,
                'email' => $user->email,
                'no_telp' => $user->no_hp,
                'nidn' => $user->nidn,

                'nim' => $user->nim,
                'kelas' => $user->kelas,
                'role_label' => $user->roles->pluck('display_name')->join(', '),
                'prodi' => $user->prodi?->only(['kode', 'nama']),
                'status' => $user->status,
                'last_login' => $user->last_login_at?->toDateTimeString(),
                'two_factor_enabled' => ! is_null($user->two_factor_confirmed_at),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'nama' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:160', Rule::unique('users', 'email')->ignore($user->id)],
            'no_telp' => ['nullable', 'string', 'max:30'],
        ]);

        $data = [
            'nama' => $validated['nama'],
            'email' => $validated['email'],
            'no_hp' => $validated['no_telp'] ?? null,
        ];

        $old = $user->only(['nama', 'email', 'no_hp']);
        $user->update($data);

        AuditTrail::create([
            'user_id' => $user->id,
            'action' => 'update_profile',
            'model_type' => 'User',
            'model_id' => $user->id,
            'old_values' => $old,
            'new_values' => $data,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Profil berhasil diperbarui.');
    }

    public function updatePassword(Request $request): RedirectResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
        ]);

        $user = $request->user();
        if (! Hash::check($request->current_password, $user->password)) {
            return back()->withErrors(['current_password' => 'Password lama tidak cocok.']);
        }

        $user->update(['password' => Hash::make($request->password)]);

        // M-21: cabut seluruh sesi lain setelah ganti password.
        // - Sanctum bearer token (mobile) dicabut semua.
        // - Session database milik user selain sesi web saat ini dihapus.
        // - Sesi web saat ini di-regenerate agar pengguna tetap login di device ini.
        $user->tokens()->delete();
        $currentSessionId = $request->session()->getId();
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->where('id', '!=', $currentSessionId)
            ->delete();
        $request->session()->regenerate();

        AuditTrail::create([
            'user_id' => $user->id,
            'action' => 'update_password',
            'model_type' => 'User',
            'model_id' => $user->id,
            'old_values' => null,
            'new_values' => ['changed_at' => now()->toDateTimeString(), 'other_sessions_revoked' => true],
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
        ]);

        return back()->with('success', 'Password berhasil diperbarui. Sesi lain telah dikeluarkan.');
    }
}
