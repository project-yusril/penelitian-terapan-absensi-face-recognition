<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Prodi;
use App\Models\ProdiSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProdiController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Prodi::with('setting');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('nama', 'like', "%{$request->search}%");
        }

        $prodis = $query->orderBy('nama')->paginate($this->resolvePerPage($request));

        return $this->paginated($prodis);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $prodi = Prodi::with(['setting', 'geofences'])->findOrFail($id);

        return $this->success($prodi);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'kode' => 'required|string|max:10|unique:prodis,kode',
            'nama' => 'required|string|max:100',
            'jenjang' => 'required|in:D3,D4,S1,S2',
            'jurusan' => 'required|string|max:100',
            'status' => 'nullable|in:aktif,nonaktif',
        ]);

        $prodi = Prodi::create($request->all());

        // Create default settings
        ProdiSetting::create([
            'prodi_id' => $prodi->id,
            'toleransi_masuk_menit' => 15,
            'batas_terlambat_persen' => 50,
            'toleransi_pulang_menit' => 15,
            'sp1_jam_mulai' => 16,
            'sp1_jam_akhir' => 31,
            'sp2_jam_mulai' => 32,
            'sp2_jam_akhir' => 37,
            'sp3_jam_mulai' => 38,
            'sp3_jam_akhir' => 45,
            'do_jam_mulai' => 46,
            'face_threshold' => 1.000,
            'default_radius_meter' => 50,
        ]);

        $prodi->load('setting');

        return $this->created($prodi, 'Prodi berhasil dibuat');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $prodi = Prodi::findOrFail($id);

        $request->validate([
            'kode' => ['sometimes', 'string', 'max:10', Rule::unique('prodis')->ignore($prodi->id)],
            'nama' => 'sometimes|string|max:100',
            'jenjang' => 'sometimes|in:D3,D4,S1,S2',
            'jurusan' => 'sometimes|string|max:100',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        $prodi->update($request->all());

        return $this->success($prodi, 'Prodi berhasil diperbarui');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $prodi = Prodi::findOrFail($id);

        // Check if prodi has users
        if ($prodi->users()->exists()) {
            return $this->error('Tidak dapat menghapus prodi yang masih memiliki user', 422);
        }

        $prodi->setting?->delete();
        $prodi->delete();

        return $this->success(message: 'Prodi berhasil dihapus');
    }

    public function updateSettings(Request $request, int $id): JsonResponse
    {
        $prodi = Prodi::findOrFail($id);

        $request->validate([
            'toleransi_masuk_menit' => 'sometimes|integer|min:0|max:60',
            'batas_terlambat_persen' => 'sometimes|integer|min:0|max:100',
            'toleransi_pulang_menit' => 'sometimes|integer|min:0|max:60',
            'sp1_jam_mulai' => 'sometimes|integer|min:1',
            'sp2_jam_mulai' => 'sometimes|integer|min:1',
            'sp3_jam_mulai' => 'sometimes|integer|min:1',
            'face_threshold' => 'sometimes|numeric|min:0|max:2',
            'default_radius_meter' => 'sometimes|integer|min:10|max:500',
        ]);

        $setting = ProdiSetting::updateOrCreate(
            ['prodi_id' => $prodi->id],
            $request->all()
        );

        return $this->success($setting, 'Settings prodi berhasil diperbarui');
    }
}
