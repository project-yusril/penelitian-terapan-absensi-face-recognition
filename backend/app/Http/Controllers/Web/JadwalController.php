<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\MataKuliah;
use App\Models\User;
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
        $kelasId = $request->integer('kelas_id');
        $sort = $request->string('sort', 'hari')->toString();
        $direction = $request->string('direction', 'asc')->toString() === 'desc' ? 'desc' : 'asc';
        $perPage = $this->resolvePerPage($request, 10);

        $allowedSorts = ['hari', 'jam_mulai', 'ruangan', 'status'];
        if (! in_array($sort, $allowedSorts, true)) {
            $sort = 'hari';
        }

        $items = Jadwal::with(['mataKuliah:id,nama,kode_mk', 'kelas:id,tingkat,nama', 'dosen:id,nama', 'geofence:id,nama'])
            ->when($search, function ($q) use ($search) {
                $q->where('ruangan', 'like', "%{$search}%")
                    ->orWhereHas('mataKuliah', fn ($m) => $m->where('nama', 'like', "%{$search}%"))
                    ->orWhereHas('dosen', fn ($m) => $m->where('nama', 'like', "%{$search}%"));
            })
            ->when($hari, fn ($q) => $q->where('hari', $hari))
            ->when($kelasId, fn ($q) => $q->where('kelas_id', $kelasId))
            ->orderBy($sort, $direction)
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Jadwal $j) => [
                'id' => $j->id,
                'mata_kuliah' => $j->mataKuliah?->nama,
                'mata_kuliah_id' => $j->mata_kuliah_id,
                'kode_mk' => $j->mataKuliah?->kode_mk,
                'kelas' => $j->kelas ? $j->kelas->tingkat.$j->kelas->nama : null,
                'kelas_id' => $j->kelas_id,
                'dosen' => $j->dosen?->nama,
                'dosen_id' => $j->dosen_id,
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
                'kelas_id' => $kelasId,
                'sort' => $sort,
                'direction' => $direction,
                'per_page' => $perPage,
            ],
            'hariOptions' => self::HARI,
            'mataKuliahs' => MataKuliah::select('id', 'kode_mk', 'nama')->where('status', 'aktif')->orderBy('kode_mk')->get(),
            'kelasOptions' => Kelas::with('semester:id,nama')
                ->where('status', 'aktif')
                ->orderBy('tingkat')->orderBy('nama')
                ->get()
                ->map(fn (Kelas $k) => [
                    'id' => $k->id,
                    'label' => $k->tingkat.$k->nama,
                    'semester' => $k->semester?->nama,
                ]),
            'dosens' => User::whereHas('roles', fn ($q) => $q->where('name', 'dosen'))
                ->select('id', 'nama')->orderBy('nama')->get(),
            'geofences' => Geofence::select('id', 'nama')->where('status', 'aktif')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateData($request);
        $this->assertNoConflict($request, null);

        $jadwal = Jadwal::create($data + ['durasi_menit' => $this->durasiMenit($data)]);

        return back()->with('success', 'Jadwal berhasil ditambahkan.');
    }

    public function update(Request $request, Jadwal $jadwal): RedirectResponse
    {
        $data = $this->validateData($request, $jadwal->id);
        $this->assertNoConflict($request, $jadwal->id);

        $jadwal->update($data + ['durasi_menit' => $this->durasiMenit($data)]);

        return back()->with('success', 'Jadwal berhasil diperbarui.');
    }

    public function destroy(Jadwal $jadwal): RedirectResponse
    {
        if ($jadwal->attendances()->exists()) {
            return back()->with('error', 'Tidak dapat menghapus jadwal yang sudah memiliki data kehadiran.');
        }
        $jadwal->delete();

        return back()->with('success', 'Jadwal berhasil dihapus.');
    }

    /**
     * Phase 3.6: anti-bentrok — dosen/kelas/ruangan yang sama di hari & jam
     * yang sama ditolak (interval setengah terbuka [start, end); jadwal
     * back-to-back tetap diizinkan).
     */
    private function assertNoConflict(Request $request, ?int $ignoreId): void
    {
        $overlap = fn ($q) => $q
            ->where('hari', $request->hari)
            ->where('status', 'aktif')
            ->where('jam_mulai', '<', $request->jam_selesai)
            ->where('jam_selesai', '>', $request->jam_mulai)
            ->when($ignoreId, fn ($q2) => $q2->whereKeyNot($ignoreId));

        $checks = [];
        if ($request->filled('dosen_id')) {
            $checks['Dosen'] = Jadwal::where('dosen_id', $request->dosen_id)->where($overlap)->exists();
        }
        if ($request->filled('kelas_id')) {
            $checks['Kelas'] = Jadwal::where('kelas_id', $request->kelas_id)->where($overlap)->exists();
        }
        if ($request->filled('ruangan')) {
            $checks['Ruangan'] = Jadwal::where('ruangan', $request->ruangan)->where($overlap)->exists();
        }

        $bentrok = collect($checks)->filter()->keys()->implode(', ');
        if ($bentrok !== '') {
            back()->with('error', "Jadwal bentrok: {$bentrok} sudah terisi di hari & jam tersebut.")->throwResponse();
        }
    }

    private function durasiMenit(array $data): int
    {
        return (int) ((strtotime($data['jam_selesai']) - strtotime($data['jam_mulai'])) / 60);
    }

    private function validateData(Request $request, ?int $ignoreId = null): array
    {
        return $request->validate([
            'mata_kuliah_id' => ['required', 'exists:mata_kuliahs,id'],
            'kelas_id' => ['required', 'exists:kelas,id'],
            'dosen_id' => ['nullable', 'exists:users,id'],
            'geofence_id' => ['nullable', 'exists:geofences,id'],
            'hari' => ['required', Rule::in(self::HARI)],
            'jam_mulai' => ['required', 'date_format:H:i'],
            'jam_selesai' => ['required', 'date_format:H:i', 'after:jam_mulai'],
            'ruangan' => ['nullable', 'string', 'max:50'],
            'status' => ['required', Rule::in(['aktif', 'nonaktif'])],
        ]);
    }
}
