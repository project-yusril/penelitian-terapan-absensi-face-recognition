<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GeofenceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Geofence::with('prodi');

        if ($request->filled('prodi_id')) {
            $query->where('prodi_id', $request->prodi_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $query->where('nama', 'like', "%{$request->search}%");
        }

        $data = $query->orderBy('nama')->paginate($this->resolvePerPage($request));

        return $this->paginated($data);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $geofence = Geofence::with(['prodi', 'jadwals'])->findOrFail($id);

        return $this->success($geofence);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'nama' => 'required|string|max:100',
            'latitude' => 'required|numeric|between:-90,90',
            'longitude' => 'required|numeric|between:-180,180',
            'radius' => 'required|integer|min:10|max:500',
            'gedung' => 'nullable|string|max:100',
            'lantai' => 'nullable|string|max:10',
            'prodi_id' => 'nullable|exists:prodis,id',
            'status' => 'nullable|in:aktif,nonaktif',
        ]);

        $geofence = Geofence::create($request->all());

        return $this->created($geofence, 'Geofence berhasil dibuat');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $geofence = Geofence::findOrFail($id);

        $request->validate([
            'nama' => 'sometimes|string|max:100',
            'latitude' => 'sometimes|numeric|between:-90,90',
            'longitude' => 'sometimes|numeric|between:-180,180',
            'radius' => 'sometimes|integer|min:10|max:500',
            'gedung' => 'sometimes|nullable|string|max:100',
            'lantai' => 'sometimes|nullable|string|max:10',
            'prodi_id' => 'sometimes|nullable|exists:prodis,id',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        $geofence->update($request->all());

        return $this->success($geofence, 'Geofence berhasil diperbarui');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $geofence = Geofence::findOrFail($id);

        if ($geofence->jadwals()->exists()) {
            return $this->error('Tidak dapat menghapus geofence yang masih digunakan jadwal', 422);
        }

        $geofence->delete();

        return $this->success(message: 'Geofence berhasil dihapus');
    }
}
