<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Jadwal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class JadwalController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Jadwal::with(['mataKuliah.prodi', 'kelas:id,tingkat,nama', 'dosen:id,nama', 'geofence']);

        if ($request->filled('mata_kuliah_id')) {
            $query->where('mata_kuliah_id', $request->mata_kuliah_id);
        }

        if ($request->filled('kelas_id')) {
            $query->where('kelas_id', $request->kelas_id);
        }

        if ($request->filled('hari')) {
            $query->where('hari', $request->hari);
        }

        if ($request->filled('geofence_id')) {
            $query->where('geofence_id', $request->geofence_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by dosen (RENCANA 2: kolom jadwals.dosen_id).
        if ($request->filled('dosen_id')) {
            $query->where('dosen_id', $request->dosen_id);
        }

        // Filter by prodi
        if ($request->filled('prodi_id')) {
            $query->whereHas('mataKuliah', fn ($q) => $q->where('prodi_id', $request->prodi_id));
        }

        $data = $query->orderByRaw("FIELD(hari, 'Senin','Selasa','Rabu','Kamis','Jumat','Sabtu')")
            ->orderBy('jam_mulai')
            ->paginate($this->resolvePerPage($request, 30));

        return $this->paginated($data);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $jadwal = Jadwal::with(['mataKuliah.prodi', 'kelas:id,tingkat,nama', 'dosen:id,nama', 'geofence'])->findOrFail($id);

        return $this->success($jadwal);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'mata_kuliah_id' => 'required|exists:mata_kuliahs,id',
            'kelas_id' => 'required|exists:kelas,id',
            'dosen_id' => 'nullable|exists:users,id',
            'geofence_id' => 'required|exists:geofences,id',
            'hari' => 'required|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu',
            'jam_mulai' => 'required|date_format:H:i',
            'jam_selesai' => 'required|date_format:H:i|after:jam_mulai',
            'ruangan' => 'nullable|string|max:50',
            'status' => 'nullable|in:aktif,nonaktif',
        ]);

        $this->assertNoConflict($request, null);

        $jadwal = Jadwal::create($request->only([
            'mata_kuliah_id', 'kelas_id', 'dosen_id', 'geofence_id',
            'hari', 'jam_mulai', 'jam_selesai', 'ruangan', 'status',
        ]));
        $jadwal->load(['mataKuliah', 'kelas:id,tingkat,nama', 'dosen:id,nama', 'geofence']);

        return $this->created($jadwal, 'Jadwal berhasil dibuat');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $jadwal = Jadwal::findOrFail($id);

        $request->validate([
            'mata_kuliah_id' => 'sometimes|exists:mata_kuliahs,id',
            'kelas_id' => 'sometimes|exists:kelas,id',
            'dosen_id' => 'sometimes|nullable|exists:users,id',
            'geofence_id' => 'sometimes|exists:geofences,id',
            'hari' => 'sometimes|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu',
            'jam_mulai' => 'sometimes|date_format:H:i',
            'jam_selesai' => 'sometimes|date_format:H:i',
            'ruangan' => 'sometimes|nullable|string|max:50',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        // Cek bentrok jika ada perubahan waktu/lokasi/dosen/kelas
        if ($request->hasAny(['kelas_id', 'dosen_id', 'ruangan', 'geofence_id', 'hari', 'jam_mulai', 'jam_selesai'])) {
            $payload = array_merge([
                'kelas_id' => $jadwal->kelas_id,
                'dosen_id' => $jadwal->dosen_id,
                'ruangan' => $jadwal->ruangan,
                'geofence_id' => $jadwal->geofence_id,
                'hari' => $jadwal->hari,
                'jam_mulai' => $jadwal->jam_mulai,
                'jam_selesai' => $jadwal->jam_selesai,
            ], $request->only(['kelas_id', 'dosen_id', 'ruangan', 'geofence_id', 'hari', 'jam_mulai', 'jam_selesai']));

            // Update parsial dapat menghasilkan rentang terbalik ketika hanya
            // salah satu jam dikirim, sehingga urutan divalidasi terhadap nilai
            // efektif, bukan hanya terhadap payload.
            if (strtotime((string) $payload['jam_selesai']) <= strtotime((string) $payload['jam_mulai'])) {
                return $this->error('Jam selesai harus setelah jam mulai', 422);
            }

            $this->assertNoConflict((object) $payload, $jadwal->id);
        }

        $jadwal->update($request->only([
            'mata_kuliah_id', 'kelas_id', 'dosen_id', 'geofence_id',
            'hari', 'jam_mulai', 'jam_selesai', 'ruangan', 'status',
        ]));
        $jadwal->load(['mataKuliah', 'kelas:id,tingkat,nama', 'dosen:id,nama', 'geofence']);

        return $this->success($jadwal, 'Jadwal berhasil diperbarui');
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $jadwal = Jadwal::findOrFail($id);

        if ($jadwal->attendances()->exists()) {
            return $this->error('Tidak dapat menghapus jadwal yang sudah memiliki data kehadiran', 422);
        }

        $jadwal->delete();

        return $this->success(message: 'Jadwal berhasil dihapus');
    }

    /**
     * Phase 3.6: anti-bentrok — dosen/kelas/ruangan yang sama di hari & jam
     * yang sama ditolak. Interval setengah terbuka [start, end): jadwal
     * back-to-back tidak dianggap bentrok.
     */
    private function assertNoConflict(object $payload, ?int $ignoreId): void
    {
        $overlap = fn ($q) => $q
            ->where('hari', $payload->hari)
            ->where('status', 'aktif')
            ->where('jam_mulai', '<', $payload->jam_selesai)
            ->where('jam_selesai', '>', $payload->jam_mulai)
            ->when($ignoreId, fn ($q2) => $q2->whereKeyNot($ignoreId));

        $checks = [];
        if (! empty($payload->dosen_id)) {
            $checks['dosen'] = Jadwal::where('dosen_id', $payload->dosen_id)->where($overlap)->exists();
        }
        if (! empty($payload->kelas_id)) {
            $checks['kelas'] = Jadwal::where('kelas_id', $payload->kelas_id)->where($overlap)->exists();
        }
        if (! empty($payload->ruangan)) {
            $checks['ruangan'] = Jadwal::where('ruangan', $payload->ruangan)->where($overlap)->exists();
        }

        $bentrok = collect($checks)->filter()->keys();
        if ($bentrok->isNotEmpty()) {
            abort(422, 'Jadwal bentrok: '.$bentrok->map(fn ($k) => ucfirst($k))->implode(', ').' sudah terisi di hari & jam tersebut.');
        }
    }
}
