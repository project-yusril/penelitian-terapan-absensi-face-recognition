<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Models\Prodi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class GeofenceController extends Controller
{
    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $sort = $request->string('sort', 'nama')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowed = ['nama', 'radius', 'gedung', 'status'];
        if (! in_array($sort, $allowed, true)) {
            $sort = 'nama';
        }

        $items = Geofence::with('prodi:id,nama')
            ->when($search, fn ($q) => $q->where('nama', 'like', "%{$search}%")->orWhere('gedung', 'like', "%{$search}%"))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Geofence $g) => [
                'id' => $g->id,
                'nama' => $g->nama,
                'latitude' => (float) $g->latitude,
                'longitude' => (float) $g->longitude,
                'radius' => $g->radius,
                'gedung' => $g->gedung,
                'lantai' => $g->lantai,
                'prodi' => $g->prodi?->nama,
                'prodi_id' => $g->prodi_id,
                'status' => $g->status,
            ]);

        return Inertia::render('Geofence/Index', [
            'items' => $items,
            'filters' => compact('search', 'sort', 'direction') + ['per_page' => $perPage],
            'prodis' => Prodi::select('id', 'nama')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Geofence::create($this->validateData($request));

        return back()->with('success', 'Lokasi geofence berhasil ditambahkan.');
    }

    public function update(Request $request, Geofence $geofence): RedirectResponse
    {
        $geofence->update($this->validateData($request));

        return back()->with('success', 'Lokasi geofence berhasil diperbarui.');
    }

    public function destroy(Geofence $geofence): RedirectResponse
    {
        if ($geofence->jadwals()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus geofence yang dipakai jadwal.');
        }
        $geofence->delete();

        return back()->with('success', 'Lokasi geofence berhasil dihapus.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'nama' => ['required', 'string', 'max:100'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius' => ['required', 'integer', 'min:5', 'max:1000'],
            'gedung' => ['nullable', 'string', 'max:100'],
            'lantai' => ['nullable', 'string', 'max:10'],
            'prodi_id' => ['nullable', 'exists:prodis,id'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
