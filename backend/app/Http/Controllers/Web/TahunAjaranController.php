<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\TahunAjaran;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TahunAjaranController extends Controller
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

        $items = TahunAjaran::withCount('semesters')
            ->when($search, fn ($q) => $q->where('nama', 'like', "%{$search}%")->orWhere('kode', 'like', "%{$search}%"))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (TahunAjaran $t) => [
                'id' => $t->id,
                'kode' => $t->kode,
                'nama' => $t->nama,
                'tanggal_mulai' => $t->tanggal_mulai?->format('Y-m-d'),
                'tanggal_selesai' => $t->tanggal_selesai?->format('Y-m-d'),
                'status' => $t->status,
                'semesters_count' => $t->semesters_count,
            ]);

        return Inertia::render('TahunAjaran/Index', [
            'items' => $items,
            'filters' => compact('search', 'sort', 'direction') + ['per_page' => $perPage],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->handleActiveToggle($data);
        TahunAjaran::create($data);

        return back()->with('success', 'Tahun ajaran berhasil ditambahkan.');
    }

    public function update(Request $request, TahunAjaran $tahunAjaran): RedirectResponse
    {
        $data = $this->validateData($request, $tahunAjaran->id);
        $this->handleActiveToggle($data, $tahunAjaran->id);
        $tahunAjaran->update($data);

        return back()->with('success', 'Tahun ajaran berhasil diperbarui.');
    }

    public function destroy(TahunAjaran $tahunAjaran): RedirectResponse
    {
        if ($tahunAjaran->semesters()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus tahun ajaran yang memiliki semester.');
        }
        $tahunAjaran->delete();

        return back()->with('success', 'Tahun ajaran berhasil dihapus.');
    }

    private function handleActiveToggle(array $data, ?int $ignoreId = null): void
    {
        if (($data['status'] ?? null) === 'aktif') {
            TahunAjaran::where('status', 'aktif')
                ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                ->update(['status' => 'nonaktif']);
        }
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'kode' => ['required', 'string', 'max:20', Rule::unique('tahun_ajarans', 'kode')->ignore($ignoreId)],
            'nama' => ['required', 'string', 'max:50'],
            'tanggal_mulai' => ['required', 'date'],
            'tanggal_selesai' => ['required', 'date', 'after:tanggal_mulai'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
