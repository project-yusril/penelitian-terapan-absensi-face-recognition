<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Kelas;
use App\Models\Prodi;
use App\Models\Semester;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class KelasController extends Controller
{
    public const TINGKAT = ['1', '2', '3', '4', '5'];

    public const HURUF = ['A', 'B', 'C', 'D', 'E'];

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $prodiId = $request->integer('prodi_id');
        $semesterId = $request->integer('semester_id');
        $sort = $request->string('sort', 'tingkat')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowedSorts = ['tingkat', 'nama', 'status'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'tingkat';
        }

        $items = Kelas::with(['prodi:id,kode,nama', 'semester:id,nama'])
            ->withCount('mahasiswaKelas')
            ->when($search, function ($q) use ($search) {
                $q->where('nama', 'like', "%{$search}%")
                    ->orWhere('tingkat', 'like', "%{$search}%");
            })
            ->when($prodiId, fn ($q) => $q->where('prodi_id', $prodiId))
            ->when($semesterId, fn ($q) => $q->where('semester_id', $semesterId))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Kelas $k) => [
                'id' => $k->id,
                'tingkat' => $k->tingkat,
                'nama' => $k->nama,
                'label' => $k->tingkat.$k->nama,
                'prodi' => $k->prodi?->nama,
                'prodi_id' => $k->prodi_id,
                'semester' => $k->semester?->nama,
                'semester_id' => $k->semester_id,
                'mahasiswa_count' => $k->mahasiswa_kelas_count,
                'status' => $k->status,
            ]);

        return Inertia::render('Kelas/Index', [
            'items' => $items,
            'filters' => [
                'search' => $search,
                'prodi_id' => $prodiId,
                'semester_id' => $semesterId,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
            'prodis' => Prodi::select('id', 'kode', 'nama')->orderBy('nama')->get(),
            'semesters' => Semester::select('id', 'nama')->orderByDesc('id')->get(),
            'tingkatOptions' => self::TINGKAT,
            'hurufOptions' => self::HURUF,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        Kelas::create($data);

        return back()->with('success', 'Kelas '.$data['tingkat'].$data['nama'].' berhasil ditambahkan.');
    }

    public function update(Request $request, Kelas $kelas): RedirectResponse
    {
        $data = $this->validateData($request, $kelas->id);
        $kelas->update($data);

        return back()->with('success', 'Kelas berhasil diperbarui.');
    }

    public function destroy(Kelas $kelas): RedirectResponse
    {
        if ($kelas->mahasiswaKelas()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus kelas yang masih memiliki mahasiswa.');
        }
        if ($kelas->jadwals()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus kelas yang masih memiliki jadwal.');
        }
        $kelas->delete();

        return back()->with('success', 'Kelas berhasil dihapus.');
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'prodi_id' => ['required', 'exists:prodis,id'],
            'semester_id' => ['required', 'exists:semesters,id'],
            'tingkat' => ['required', Rule::in(self::TINGKAT)],
            'nama' => ['required', 'string', 'max:5', Rule::in(self::HURUF)],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ], [], [
            'tingkat' => 'Tingkat',
            'nama' => 'Huruf',
        ]);

        // Anti-duplikat kombinasi (prodi, semester, tingkat, huruf) dengan
        // pesan yang jelas, selaras unique constraint di level database.
        $exists = Kelas::where('prodi_id', $data['prodi_id'])
            ->where('semester_id', $data['semester_id'])
            ->where('tingkat', $data['tingkat'])
            ->where('nama', $data['nama'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'nama' => "Kelas {$data['tingkat']}{$data['nama']} sudah ada untuk prodi & semester ini.",
            ]);
        }

        return $data;
    }
}
