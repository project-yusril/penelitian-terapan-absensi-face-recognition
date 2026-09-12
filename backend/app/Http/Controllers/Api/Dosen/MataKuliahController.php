<?php

namespace App\Http\Controllers\Api\Dosen;

use App\Http\Controllers\Controller;
use App\Models\Jadwal;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MataKuliahController extends Controller
{
    /**
     * RENCANA 2: mata kuliah yang diampu = jadwal dengan dosen_id = dosen ini.
     * Setiap entri membawa kelas & jadwal terkait.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = Jadwal::with(['mataKuliah.semester', 'mataKuliah.prodi', 'kelas:id,tingkat,nama', 'geofence'])
            ->where('dosen_id', $user->id)
            ->where('status', 'aktif');

        if ($request->filled('semester_id')) {
            $query->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $request->semester_id));
        } else {
            // Default: semester aktif
            $semesterAktif = Semester::where('status', 'aktif')->first();
            if ($semesterAktif) {
                $query->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterAktif->id));
            }
        }

        $jadwals = $query->orderBy('hari')->orderBy('jam_mulai')->get();

        // Grouping per mata kuliah: satu MK bisa diampu untuk beberapa kelas.
        $data = $jadwals->groupBy('mata_kuliah_id')->map(function ($items) {
            $mk = $items->first()->mataKuliah;

            return [
                'id' => $mk->id,
                'kode_mk' => $mk->kode_mk,
                'nama' => $mk->nama,
                'sks' => $mk->sks,
                'semester_id' => $mk->semester_id,
                'semester' => $mk->semester?->only(['id', 'nama']),
                'prodi' => $mk->prodi?->only(['id', 'kode', 'nama']),
                'total_pertemuan' => $mk->total_pertemuan,
                'status' => $mk->status,
                'kelas' => $items->map(fn ($j) => $j->kelas ? $j->kelas->tingkat.$j->kelas->nama : null)->filter()->values(),
                'jadwal' => $items->map(fn ($j) => [
                    'id' => $j->id,
                    'kelas_id' => $j->kelas_id,
                    'kelas' => $j->kelas ? $j->kelas->tingkat.$j->kelas->nama : null,
                    'hari' => $j->hari,
                    'jam_mulai' => $j->jam_mulai,
                    'jam_selesai' => $j->jam_selesai,
                    'ruangan' => $j->ruangan,
                    'geofence' => $j->geofence?->only(['id', 'nama']),
                ])->values(),
            ];
        })->values();

        return $this->success($data);
    }

    /**
     * List mahasiswa di mata kuliah tertentu (dari kelas yang diampu dosen).
     */
    public function mahasiswa(Request $request, int $id): JsonResponse
    {
        $user = $request->user();

        $kelasIds = Jadwal::where('dosen_id', $user->id)
            ->where('mata_kuliah_id', $id)
            ->pluck('kelas_id')
            ->filter()
            ->unique()
            ->values();

        abort_if($kelasIds->isEmpty(), 404, 'Mata kuliah tidak ditemukan atau tidak diampu dosen ini.');

        $mahasiswas = User::whereHas('mahasiswaKelas', fn ($q) => $q->whereIn('kelas_id', $kelasIds))
            ->select('users.id', 'users.nama', 'users.nim', 'users.kelas', 'users.foto_profil')
            ->orderBy('users.nim')
            ->get();

        return $this->success($mahasiswas);
    }
}
