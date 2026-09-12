<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\MataKuliah;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MataKuliahController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = MataKuliah::with(['semester', 'prodi']);

        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        if ($request->filled('prodi_id')) {
            $query->where('prodi_id', $request->prodi_id);
        }

        if ($request->filled('dosen_id')) {
            // RENCANA 2: dosen bukan kolom MK lagi — filter via jadwal.
            $query->whereHas('jadwals', fn ($q) => $q->where('dosen_id', $request->dosen_id));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('kode_mk', 'like', "%{$search}%");
            });
        }

        $data = $query->orderBy('kode_mk')->paginate($this->resolvePerPage($request));

        return $this->paginated($data);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $mk = MataKuliah::with(['semester', 'prodi', 'jadwals.kelas:id,tingkat,nama', 'jadwals.dosen:id,nama'])
            ->findOrFail($id);

        return $this->success($mk);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'kode_mk' => 'required|string|max:20',
            'nama' => 'required|string|max:100',
            'sks' => 'required|integer|min:1|max:6',
            'semester_id' => 'required|exists:semesters,id',
            'prodi_id' => 'required|exists:prodis,id',
            'total_pertemuan' => 'nullable|integer|min:1|max:32',
            'status' => 'nullable|in:aktif,nonaktif',
        ]);

        $mk = MataKuliah::create(array_merge(
            $request->only(['kode_mk', 'nama', 'sks', 'semester_id', 'prodi_id', 'total_pertemuan', 'status']),
            ['total_pertemuan' => $request->total_pertemuan ?? 16]
        ));

        $mk->load(['semester', 'prodi']);

        return $this->created($mk, 'Mata kuliah berhasil dibuat');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $mk = MataKuliah::findOrFail($id);

        $request->validate([
            'kode_mk' => 'sometimes|string|max:20',
            'nama' => 'sometimes|string|max:100',
            'sks' => 'sometimes|integer|min:1|max:6',
            'semester_id' => 'sometimes|exists:semesters,id',
            'prodi_id' => 'sometimes|exists:prodis,id',
            'total_pertemuan' => 'sometimes|integer|min:1|max:32',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        $mk->update($request->only(['kode_mk', 'nama', 'sks', 'semester_id', 'prodi_id', 'total_pertemuan', 'status']));
        $mk->load(['semester', 'prodi']);

        return $this->success($mk, 'Mata kuliah berhasil diperbarui');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $mk = MataKuliah::findOrFail($id);

        if ($mk->attendances()->exists()) {
            return $this->error('Tidak dapat menghapus mata kuliah yang sudah memiliki data kehadiran', 422);
        }

        $mk->jadwals()->delete();
        $mk->delete();

        return $this->success(message: 'Mata kuliah berhasil dihapus');
    }

    /**
     * RENCANA 2: enrollment manual ke pivot lama dihapus — peserta mengikuti
     * kelas pada jadwal. Endpoint dipertahankan agar klien lama tidak 500,
     * tetapi tidak lagi mengubah apa pun.
     */
    public function enrollMahasiswa(Request $request, int $id): JsonResponse
    {
        $mk = MataKuliah::findOrFail($id);

        return $this->success([
            'enrolled_count' => 0,
            'total_mahasiswa' => $this->pesertaViaKelas($mk)->count(),
        ], 'Peserta mata kuliah otomatis mengikuti kelas pada jadwal');
    }

    /**
     * RENCANA 2: sama seperti enroll — tidak ada lagi operasi manual.
     */
    public function removeMahasiswa(Request $request, int $id): JsonResponse
    {
        $mk = MataKuliah::findOrFail($id);

        return $this->success([
            'removed_count' => 0,
            'total_mahasiswa' => $this->pesertaViaKelas($mk)->count(),
        ], 'Peserta mata kuliah otomatis mengikuti kelas pada jadwal');
    }

    private function pesertaViaKelas(MataKuliah $mk): \Illuminate\Support\Collection
    {
        $kelasIds = $mk->jadwals()->pluck('kelas_id')->filter()->unique()->values();
        if ($kelasIds->isEmpty()) {
            return collect();
        }

        return User::whereHas('mahasiswaKelas', fn ($q) => $q->whereIn('kelas_id', $kelasIds))->get();
    }
}
