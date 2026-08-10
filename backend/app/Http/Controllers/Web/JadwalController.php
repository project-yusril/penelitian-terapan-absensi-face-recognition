<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class JadwalController extends Controller
{
    private const HARI = ['senin', 'selasa', 'rabu', 'kamis', 'jumat', 'sabtu', 'minggu'];

    public function index(Request $request): Response
    {
        $search = $request->string('search')->toString();
        $hari = $request->string('hari')->toString();
        $sort = $request->string('sort', 'hari')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowedSorts = ['hari', 'jam_mulai', 'ruangan', 'status'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'hari';
        }

        $items = Jadwal::with(['mataKuliah:id,nama,kode_mk', 'geofence:id,nama'])
            ->when($search, function ($q) use ($search) {
                $q->where('ruangan', 'like', "%{$search}%")
                    ->orWhereHas('mataKuliah', fn ($m) => $m->where('nama', 'like', "%{$search}%"));
            })
            ->when($hari, fn ($q) => $q->where('hari', $hari))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Jadwal $j) => [
                'id' => $j->id,
                'mata_kuliah' => $j->mataKuliah?->nama,
                'mata_kuliah_id' => $j->mata_kuliah_id,
                'kode_mk' => $j->mataKuliah?->kode_mk,
                'geofence' => $j->geofence?->nama,
                'geofence_id' => $j->geofence_id,
                'hari' => $j->hari,

                'jam_mulai' => substr((string) $j->jam_mulai, 0, 5),
                'jam_selesai' => substr((string) $j->jam_selesai, 0, 5),
                'ruangan' => $j->ruangan,
                'durasi_menit' => $j->durasi_menit,
                'status' => $j->status,
            ]);

        return Inertia::render('Jadwal/Index', [
            'items' => $items,
            'filters' => [
                'search' => $search,
                'hari' => $hari,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
            'hariOptions' => self::HARI,
            'mataKuliahs' => MataKuliah::select('id', 'kode_mk', 'nama')->where('status', 'aktif')->get(),
            'geofences' => Geofence::select('id', 'nama')->where('status', 'aktif')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        Jadwal::create($data);

        return back()->with('success', 'Jadwal berhasil ditambahkan.');
    }

    public function update(Request $request, Jadwal $jadwal): RedirectResponse
    {
        $data = $this->validateData($request);
        $jadwal->update($data);

        return back()->with('success', 'Jadwal berhasil diperbarui.');
    }

    public function destroy(Jadwal $jadwal): RedirectResponse
    {
        $jadwal->delete();

        return back()->with('success', 'Jadwal berhasil dihapus.');
    }

    private function validateData(Request $request): array
    {
        return $request->validate([
            'mata_kuliah_id' => ['required', 'exists:mata_kuliahs,id'],
            'geofence_id' => ['nullable', 'exists:geofences,id'],
            'hari' => ['required', Rule::in(self::HARI)],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i', 'after:jam_mulai'],
            'ruangan' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
