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

        $allowedSorts = ['kode_mk', 'nama', 'sks', 'kelas', 'status'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'kode_mk';
        }

        $items = MataKuliah::with(['prodi:id,kode,nama', 'dosen:id,nama', 'semester:id,nama'])
            ->withCount('mahasiswas')
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
                'kelas' => $m->kelas,
                'total_pertemuan' => $m->total_pertemuan,
                'status' => $m->status,
                'prodi' => $m->prodi?->nama,
                'prodi_id' => $m->prodi_id,
                'dosen' => $m->dosen?->nama,
                'dosen_id' => $m->dosen_id,
                'semester' => $m->semester?->nama,
                'semester_id' => $m->semester_id,
                'mahasiswas_count' => $m->mahasiswas_count,
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
            'dosens' => User::whereHas('roles', fn ($q) => $q->where('name', 'dosen'))
                ->select('id', 'nama')->orderBy('nama')->get(),
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
     * Data peserta MK + kandidat mahasiswa yang bisa di-enroll (1 prodi).
     * Dipakai modal "Kelola Peserta".
     */
    public function mahasiswa(MataKuliah $matkul): Response
    {
        $enrolled = $matkul->mahasiswas()
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
                'kelas' => $matkul->kelas,
                'prodi' => $matkul->prodi?->nama,
            ],
            'enrolled' => $enrolled,
            'available' => $available,
        ]);
    }

    public function enroll(Request $request, MataKuliah $matkul): RedirectResponse
    {
        $data = $request->validate([
            'mahasiswa_ids' => ['required', 'array', 'min:1'],
            'mahasiswa_ids.*' => ['integer', 'exists:users,id'],
        ]);

        // syncWithoutDetaching agar tidak menggandakan peserta yang sudah ada.
        $matkul->mahasiswas()->syncWithoutDetaching($data['mahasiswa_ids']);

        return back()->with('success', count($data['mahasiswa_ids']).' mahasiswa berhasil di-enroll.');
    }

    public function unenroll(MataKuliah $matkul, User $mahasiswa): RedirectResponse
    {
        $matkul->mahasiswas()->detach($mahasiswa->id);

        return back()->with('success', 'Mahasiswa berhasil dikeluarkan dari mata kuliah.');
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'kode_mk' => ['required', 'string', 'max:30', Rule::unique('mata_kuliahs', 'kode_mk')->ignore($ignoreId)],
            'nama' => ['required', 'string', 'max:255'],
            'sks' => ['required', 'integer', 'min:1', 'max:6'],
            'semester_id' => ['required', 'exists:semesters,id'],
            'prodi_id' => ['required', 'exists:prodis,id'],
            'dosen_id' => ['nullable', 'exists:users,id'],
            'kelas' => ['nullable', 'string', 'max:10'],
            'total_pertemuan' => ['required', 'integer', 'min:1', 'max:32'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
