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
        $query = MataKuliah::with(['semester', 'prodi', 'dosen']);

        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        }

        if ($request->filled('prodi_id')) {
            $query->where('prodi_id', $request->prodi_id);
        }

        if ($request->filled('dosen_id')) {
            $query->where('dosen_id', $request->dosen_id);
        }

        if ($request->filled('kelas')) {
            $query->where('kelas', $request->kelas);
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
        $mk = MataKuliah::with(['semester', 'prodi', 'dosen', 'mahasiswas', 'jadwals'])
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
            'dosen_id' => 'required|exists:users,id',
            'kelas' => 'required|string|max:10',
            'total_pertemuan' => 'nullable|integer|min:1|max:32',
            'status' => 'nullable|in:aktif,nonaktif',
        ]);

        $mk = MataKuliah::create(array_merge(
            $request->all(),
            ['total_pertemuan' => $request->total_pertemuan ?? 16]
        ));

        $mk->load(['semester', 'prodi', 'dosen']);

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
            'dosen_id' => 'sometimes|exists:users,id',
            'kelas' => 'sometimes|string|max:10',
            'total_pertemuan' => 'sometimes|integer|min:1|max:32',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        $mk->update($request->all());
        $mk->load(['semester', 'prodi', 'dosen']);

        return $this->success($mk, 'Mata kuliah berhasil diperbarui');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $mk = MataKuliah::findOrFail($id);

        if ($mk->attendances()->exists()) {
            return $this->error('Tidak dapat menghapus mata kuliah yang sudah memiliki data kehadiran', 422);
        }

        $mk->mahasiswas()->detach();
        $mk->jadwals()->delete();
        $mk->delete();

        return $this->success(message: 'Mata kuliah berhasil dihapus');
    }

    /**
     * Enroll mahasiswa ke mata kuliah
     */
    public function enrollMahasiswa(Request $request, int $id): JsonResponse
    {
        $mk = MataKuliah::findOrFail($id);

        $request->validate([
            'mahasiswa_ids' => 'required|array|min:1',
            'mahasiswa_ids.*' => 'exists:users,id',
        ]);

        // Verify all are mahasiswa
        $validMahasiswas = User::whereIn('id', $request->mahasiswa_ids)
            ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->pluck('id');

        $mk->mahasiswas()->syncWithoutDetaching($validMahasiswas);

        return $this->success([
            'enrolled_count' => $validMahasiswas->count(),
            'total_mahasiswa' => $mk->mahasiswas()->count(),
        ], 'Mahasiswa berhasil di-enroll');
    }

    /**
     * Remove mahasiswa dari mata kuliah
     */
    public function removeMahasiswa(Request $request, int $id): JsonResponse
    {
        $mk = MataKuliah::findOrFail($id);

        $request->validate([
            'mahasiswa_ids' => 'required|array|min:1',
            'mahasiswa_ids.*' => 'exists:users,id',
        ]);

        // Check if any have existing attendance
        $withAttendance = Attendance::where('mata_kuliah_id', $id)
            ->whereIn('user_id', $request->mahasiswa_ids)
            ->distinct()
            ->pluck('user_id');

        if ($withAttendance->isNotEmpty()) {
            return $this->error(
                'Tidak dapat menghapus mahasiswa yang sudah memiliki data kehadiran. '
                .'ID mahasiswa dengan kehadiran: '.$withAttendance->implode(', '),
                422
            );
        }

        $mk->mahasiswas()->detach($request->mahasiswa_ids);

        return $this->success([
            'removed_count' => count($request->mahasiswa_ids),
            'total_mahasiswa' => $mk->mahasiswas()->count(),
        ], 'Mahasiswa berhasil dihapus dari mata kuliah');
    }
}
