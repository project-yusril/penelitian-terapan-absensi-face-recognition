<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TahunAjaranController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TahunAjaran::with('semesters');

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->orderByDesc('tanggal_mulai')->paginate($this->resolvePerPage($request));

        return $this->paginated($data);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $ta = TahunAjaran::with('semesters')->findOrFail($id);

        return $this->success($ta);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'kode' => 'required|string|max:20|unique:tahun_ajarans,kode',
            'nama' => 'nullable|string|max:50',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after:tanggal_mulai',
            'status' => 'nullable|in:aktif,nonaktif',
        ]);

        // Jika status aktif, nonaktifkan yang lain
        if ($request->status === 'aktif') {
            TahunAjaran::where('status', 'aktif')->update(['status' => 'nonaktif']);
        }

        $ta = TahunAjaran::create($request->all());

        return $this->created($ta, 'Tahun ajaran berhasil dibuat');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $ta = TahunAjaran::findOrFail($id);

        $request->validate([
            'kode' => ['sometimes', 'string', 'max:20', Rule::unique('tahun_ajarans')->ignore($ta->id)],
            'nama' => 'sometimes|string|max:50',
            'tanggal_mulai' => 'sometimes|date',
            'tanggal_selesai' => 'sometimes|date|after:tanggal_mulai',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        if ($request->status === 'aktif') {
            TahunAjaran::where('status', 'aktif')->where('id', '!=', $ta->id)->update(['status' => 'nonaktif']);
        }

        $ta->update($request->all());

        return $this->success($ta, 'Tahun ajaran berhasil diperbarui');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $ta = TahunAjaran::findOrFail($id);

        if ($ta->semesters()->exists()) {
            return $this->error('Tidak dapat menghapus tahun ajaran yang memiliki semester', 422);
        }

        $ta->delete();

        return $this->success(message: 'Tahun ajaran berhasil dihapus');
    }
}
