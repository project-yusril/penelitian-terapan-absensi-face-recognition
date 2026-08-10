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
        $query = Jadwal::with(['mataKuliah.dosen', 'mataKuliah.prodi', 'geofence']);

        if ($request->filled('mata_kuliah_id')) {
            $query->where('mata_kuliah_id', $request->mata_kuliah_id);
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

        // Filter by dosen
        if ($request->filled('dosen_id')) {
            $query->whereHas('mataKuliah', fn ($q) => $q->where('dosen_id', $request->dosen_id));
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
        $jadwal = Jadwal::with(['mataKuliah.dosen', 'mataKuliah.prodi', 'geofence'])->findOrFail($id);

        return $this->success($jadwal);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'mata_kuliah_id' => 'required|exists:mata_kuliahs,id',
            'geofence_id' => 'required|exists:geofences,id',
            'hari' => 'required|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu',
            'jam_mulai' => 'required|date_format:H:i',
            'jam_selesai' => 'required|date_format:H:i|after:jam_mulai',
            'ruangan' => 'nullable|string|max:50',
            'status' => 'nullable|in:aktif,nonaktif',
        ]);

        // Cek bentrok ruangan
        // Overlap interval setengah terbuka: [start, end). Jadwal back-to-back
        // (08:00-09:00 dan 09:00-10:00) tidak dianggap bentrok.
        $bentrok = Jadwal::where('geofence_id', $request->geofence_id)
            ->where('hari', $request->hari)
            ->where('status', 'aktif')
            ->where('jam_mulai', '<', $request->jam_selesai)
            ->where('jam_selesai', '>', $request->jam_mulai)
            ->exists();

        if ($bentrok) {
            return $this->error('Jadwal bentrok dengan jadwal lain di lokasi yang sama', 422);
        }

        $jadwal = Jadwal::create($request->all());
        $jadwal->load(['mataKuliah.dosen', 'geofence']);

        return $this->created($jadwal, 'Jadwal berhasil dibuat');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $jadwal = Jadwal::findOrFail($id);

        $request->validate([
            'mata_kuliah_id' => 'sometimes|exists:mata_kuliahs,id',
            'geofence_id' => 'sometimes|exists:geofences,id',
            'hari' => 'sometimes|in:Senin,Selasa,Rabu,Kamis,Jumat,Sabtu',
            'jam_mulai' => 'sometimes|date_format:H:i',
            'jam_selesai' => 'sometimes|date_format:H:i',
            'ruangan' => 'sometimes|nullable|string|max:50',
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        // Cek bentrok jika ada perubahan waktu/lokasi
        if ($request->hasAny(['geofence_id', 'hari', 'jam_mulai', 'jam_selesai'])) {
            $geofenceId = $request->geofence_id ?? $jadwal->geofence_id;
            $hari = $request->hari ?? $jadwal->hari;
            $jamMulai = $request->jam_mulai ?? $jadwal->jam_mulai;
            $jamSelesai = $request->jam_selesai ?? $jadwal->jam_selesai;

            // Update parsial dapat menghasilkan rentang terbalik ketika hanya
            // salah satu jam dikirim, sehingga urutan divalidasi terhadap nilai
            // efektif, bukan hanya terhadap payload.
            if (strtotime((string) $jamSelesai) <= strtotime((string) $jamMulai)) {
                return $this->error('Jam selesai harus setelah jam mulai', 422);
            }

            // Overlap interval setengah terbuka: [start, end). Jadwal back-to-back
            // tidak dianggap bentrok.
            $bentrok = Jadwal::where('geofence_id', $geofenceId)
                ->where('hari', $hari)
                ->where('id', '!=', $jadwal->id)
                ->where('status', 'aktif')
                ->where('jam_mulai', '<', $jamSelesai)
                ->where('jam_selesai', '>', $jamMulai)
                ->exists();

            if ($bentrok) {
                return $this->error('Jadwal bentrok dengan jadwal lain di lokasi yang sama', 422);
            }
        }

        $jadwal->update($request->all());
        $jadwal->load(['mataKuliah.dosen', 'geofence']);

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
}
