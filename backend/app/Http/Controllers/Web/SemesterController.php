<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\AlphaAccumulation;
use App\Models\Semester;
use App\Models\TahunAjaran;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class SemesterController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $sort = $request->string('sort', 'tanggal_mulai')->toString();
        $direction = $request->string('direction', 'desc')->toString() === 'asc' ? 'asc' : 'desc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowed = ['kode', 'nama', 'tanggal_mulai', 'status'];
        if (! in_array($sort, $allowed, true)) {
            $sort = 'tanggal_mulai';
        }

        $items = Semester::with('tahunAjaran:id,nama')
            ->withCount('mataKuliahs')
            ->when($search, fn ($q) => $q->where('kode', 'like', "%{$search}%"))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Semester $s) => [
                'id' => $s->id,
                'kode' => $s->kode,
                'nama' => $s->nama,
                'tahun_ajaran' => $s->tahunAjaran?->nama,
                'tahun_ajaran_id' => $s->tahun_ajaran_id,
                'tanggal_mulai' => $s->tanggal_mulai?->format('Y-m-d'),
                'tanggal_selesai' => $s->tanggal_selesai?->format('Y-m-d'),
                'status' => $s->status,
                'mata_kuliahs_count' => $s->mata_kuliahs_count,
            ]);

        return Inertia::render('Semester/Index', [
            'items' => $items,
            'filters' => compact('search', 'sort', 'direction') + ['per_page' => $perPage],
            'tahunAjarans' => TahunAjaran::select('id', 'nama')->orderByDesc('id')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $semester = DB::transaction(function () use ($data): Semester {
            DB::table('tahun_ajarans')->orderBy('id')->lockForUpdate()->get(['id']);
            $this->handleActiveToggle($data);

            return Semester::create($data);
        });
        if ($semester->status === 'aktif') {
            $this->createAlphaAccumulations($semester->id);
        }

        return back()->with('success', 'Semester berhasil ditambahkan.');
    }

    public function update(Request $request, Semester $semester): RedirectResponse
    {
        $data = $this->validateData($request, $semester->id);
        $wasActive = $semester->status === 'aktif';
        DB::transaction(function () use ($data, $semester): void {
            DB::table('tahun_ajarans')->orderBy('id')->lockForUpdate()->get(['id']);
            $this->handleActiveToggle($data, $semester->id);
            $semester->update($data);
        });
        if (! $wasActive && $semester->status === 'aktif') {
            $this->createAlphaAccumulations($semester->id);
        }

        return back()->with('success', 'Semester berhasil diperbarui.');
    }

    public function destroy(Semester $semester): RedirectResponse
    {
        if ($semester->mataKuliahs()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus semester yang memiliki mata kuliah.');
        }
        $semester->delete();

        return back()->with('success', 'Semester berhasil dihapus.');
    }

    private function handleActiveToggle(array $data, ?int $ignoreId = null): void
    {
        if (($data['status'] ?? null) === 'aktif') {
            Semester::where('status', 'aktif')
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->update(['status' => 'nonaktif']);
        }
    }

    private function createAlphaAccumulations(int $semesterId): void
    {
        $mahasiswaIds = User::whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('status', 'aktif')->pluck('id');

        foreach ($mahasiswaIds as $mhsId) {
            AlphaAccumulation::firstOrCreate(
                ['user_id' => $mhsId, 'semester_id' => $semesterId],
                ['total_alpha_menit' => 0, 'sp_status' => 'aman', 'last_calculated_at' => now()],
            );
        }
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'tahun_ajaran_id' => ['required', 'exists:tahun_ajarans,id'],
            'nama' => ['required', Rule::in(['Ganjil', 'Genap'])],
            'kode' => ['required', 'string', 'max:20', Rule::unique('semesters', 'kode')->ignore($ignoreId)],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after:tanggal_mulai'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
