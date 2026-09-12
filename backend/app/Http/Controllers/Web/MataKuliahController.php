<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class MataKuliahController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $prodiId = $request->integer('prodi_id');
        $sort = $request->string('sort', 'kode_mk')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowedSorts = ['kode_mk', 'nama', 'sks', 'status'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'kode_mk';
        }

        // RENCANA 2: mata kuliah murni master kurikulum + tingkat (tanpa kelas/dosen).
        $items = MataKuliah::with(['prodi:id,kode,nama', 'semester:id,nama'])
            ->when($search, function ($q) use ($search) {
                $q->where(function ($sub) use ($search) {
                    $sub->where('nama', 'like', "%{$search}%")
                        ->orWhere('kode_mk', 'like', "%{$search}%");
                });
            })
            ->when($prodiId, fn ($q) => $q->where('prodi_id', $prodiId))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (MataKuliah $m) => [
                'id' => $m->id,
                'kode_mk' => $m->kode_mk,
                'nama' => $m->nama,
                'sks' => $m->sks,
                'total_pertemuan' => $m->total_pertemuan,
                'status' => $m->status,
                'prodi' => $m->prodi?->nama,
                'prodi_id' => $m->prodi_id,
                'semester' => $m->semester?->nama,
                'semester_id' => $m->semester_id,
            ]);

        return Inertia::render('MataKuliah/Index', [
            'items' => $items,
            'filters' => [
                'search' => $search,
                'prodi_id' => $prodiId,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
            'prodis' => Prodi::select('id', 'kode', 'nama')->get(),
            'semesters' => Semester::select('id', 'nama')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        MataKuliah::create($data);

        return back()->with('success', 'Mata kuliah berhasil ditambahkan.');
    }

    public function update(Request $request, MataKuliah $matkul): RedirectResponse
    {
        $data = $this->validateData($request, $matkul->id);
        $matkul->update($data);

        return back()->with('success', 'Mata kuliah berhasil diperbarui.');
    }

    public function destroy(MataKuliah $matkul): RedirectResponse
    {
        $matkul->delete();

        return back()->with('success', 'Mata kuliah berhasil dihapus.');
    }

    /**
     * Data peserta MK (RENCANA 2): peserta = mahasiswa dari kelas yang
     * terkait lewat jadwal. Enrollment manual ke pivot lama tidak lagi
     * menjadi sumber kebenaran.
     */
    public function mahasiswa(MataKuliah $matkul): Response
    {
        // Semua kelas yang mengampu MK ini (via jadwal).
        $kelasIds = $matkul->jadwals()->pluck('kelas_id')->filter()->unique()->values();

        $enrolled = $kelasIds->isEmpty()
            ? collect()
            : User::whereHas('mahasiswaKelas', fn ($q) => $q->whereIn('kelas_id', $kelasIds))
                ->select('users.id', 'users.nama', 'users.nim', 'users.kelas')
                ->orderBy('users.nama')
                ->get();

        $enrolledIds = $enrolled->pluck('id');

        $available = User::whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('status', 'aktif')
            ->where('prodi_id', $matkul->prodi_id)
            ->whereNotIn('id', $enrolledIds)
            ->select('id', 'nama', 'nim', 'kelas')
            ->orderBy('nama')
            ->get();

        return Inertia::render('MataKuliah/Peserta', [
            'mataKuliah' => [
                'id' => $matkul->id,
                'kode_mk' => $matkul->kode_mk,
                'nama' => $matkul->nama,
                'prodi' => $matkul->prodi?->nama,
            ],
            'enrolled' => $enrolled,
            'available' => $available,
        ]);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'kode_mk' => ['required', 'string', 'max:30', Rule::unique('mata_kuliahs', 'kode_mk')->ignore($ignoreId)],
            'nama' => ['required', 'string', 'max:255'],
            'sks' => ['required', 'integer', 'min:1', 'max:6'],
            'semester_id' => ['required', 'exists:semesters,id'],
            'prodi_id' => ['required', 'exists:prodis,id'],
            'total_pertemuan' => ['required', 'integer', 'min:1', 'max:32'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
