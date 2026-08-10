<?php

namespace App\Http\Controllers\Api\Dosen;

use App\Http\Controllers\Controller;
use App\Models\MataKuliah;
use App\Models\Semester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MataKuliahController extends Controller
{
    /**
     * List mata kuliah yang diampu dosen
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = MataKuliah::with(['semester', 'prodi', 'jadwals.geofence'])
            ->where('dosen_id', $user->id)
            ->where('status', 'aktif');

        if ($request->filled('semester_id')) {
            $query->where('semester_id', $request->semester_id);
        } else {
            // Default: semester aktif
            $semesterAktif = Semester::where('status', 'aktif')->first();
            if ($semesterAktif) {
                $query->where('semester_id', $semesterAktif->id);
            }
        }

        $data = $query->withCount('mahasiswas')->get();

        return $this->success($data);
    }

    /**
     * List mahasiswa di mata kuliah tertentu
     */
    public function mahasiswa(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $mk = MataKuliah::where('id', $id)
            ->where('dosen_id', $user->id)
            ->firstOrFail();

        $mahasiswas = $mk->mahasiswas()
            ->select('users.id', 'users.nama', 'users.nim', 'users.kelas', 'users.foto_profil')
            ->orderBy('users.nim')
            ->get();

        return $this->success($mahasiswas);
    }
}
