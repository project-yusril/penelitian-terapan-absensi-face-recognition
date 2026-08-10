<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();
        $user->load('roles', 'prodi');

        return $this->success($user);
    }

    public function update(Request $request): JsonResponse
    {
        $request->validate([
            'nama' => 'sometimes|string|max:150',
            'no_hp' => 'sometimes|string|max:20',
            'tempat_lahir' => 'sometimes|string|max:100',
            'tanggal_lahir' => 'sometimes|date',
            'alamat' => 'sometimes|string',
            'jenis_kelamin' => 'sometimes|in:L,P',
        ]);

        $user = $request->user();
        $user->update($request->only([
            'nama', 'no_hp', 'tempat_lahir', 'tanggal_lahir', 'alamat', 'jenis_kelamin',
        ]));

        return $this->success($user->fresh(), 'Profil berhasil diperbarui');
    }

    public function uploadFoto(Request $request): JsonResponse
    {
        $request->validate([
            'foto' => 'required|image|mimes:jpeg,png,jpg|max:2048',
        ]);

        $user = $request->user();

        // Delete old photo
        if ($user->foto_profil) {
            Storage::disk('public')->delete($user->foto_profil);
        }

        $path = $request->file('foto')->store('foto-profil', 'public');
        $user->update(['foto_profil' => $path]);

        return $this->success([
            'foto_profil' => $path,
            'url' => Storage::disk('public')->url($path),
        ], 'Foto profil berhasil diupload');
    }

    public function uploadSignature(Request $request): JsonResponse
    {
        $user = $request->user();

        // Hanya Kaprodi, Ketua Jurusan, atau Admin yang boleh memiliki tanda tangan digital
        if (! $user->hasAnyRole(['kaprodi', 'ketua_jurusan', 'super_admin'])) {
            return $this->error('Anda tidak memiliki akses untuk mengupload tanda tangan digital', 403);
        }

        $request->validate([
            'tanda_tangan' => 'required|image|mimes:png,jpg,jpeg|max:1024',
        ]);

        // Delete old signature
        if ($user->tanda_tangan) {
            Storage::disk('public')->delete($user->tanda_tangan);
        }

        $path = $request->file('tanda_tangan')->store('tanda-tangan', 'public');
        $user->update(['tanda_tangan' => $path]);

        return $this->success([
            'tanda_tangan' => $path,
            'url' => Storage::disk('public')->url($path),
        ], 'Tanda tangan digital berhasil diupload');
    }
}
