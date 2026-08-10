<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AlphaAccumulation;
use App\Models\Semester;
use App\Models\SpRecord;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SemesterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = Semester::with('tahunAjaran');

        if ($request->filled('tahun_ajaran_id')) {
            $query->where('tahun_ajaran_id', $request->tahun_ajaran_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->orderByDesc('tanggal_mulai')->paginate($this->resolvePerPage($request));

        return $this->paginated($data);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $semester = Semester::with(['tahunAjaran', 'mataKuliahs'])->findOrFail($id);

        return $this->success($semester);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'tahun_ajaran_id' => 'required|exists:tahun_ajarans,id',
            'nama' => 'required|in:Ganjil,Genap',
            'kode' => 'required|string|max:20|unique:semesters,kode',
            'tanggal_mulai' => 'required|date',
            'tanggal_selesai' => 'required|date|after:tanggal_mulai',
            'status' => 'nullable|in:aktif,nonaktif',
        ]);

        $semester = DB::transaction(function () use ($request): Semester {
            DB::table('tahun_ajarans')->orderBy('id')->lockForUpdate()->get(['id']);
            if ($request->status === 'aktif') {
                Semester::where('status', 'aktif')->update(['status' => 'nonaktif']);
            }

            return Semester::create($request->only(['tahun_ajaran_id', 'nama', 'kode', 'tanggal_mulai', 'tanggal_selesai', 'status']));
        });
        $semester->load('tahunAjaran');

        // Jika semester diaktifkan, buat alpha_accumulations untuk semua mahasiswa aktif
        if ($semester->status === 'aktif') {
            $this->createAlphaAccumulations($semester->id);
        }

        return $this->created($semester, 'Semester berhasil dibuat');
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $semester = Semester::findOrFail($id);

        $request->validate([
            'tahun_ajaran_id' => 'sometimes|exists:tahun_ajarans,id',
            'nama' => 'sometimes|in:Ganjil,Genap',
            'kode' => ['sometimes', 'string', 'max:20', Rule::unique('semesters', 'kode')->ignore($semester->id)],
            'tanggal_mulai' => ['sometimes', 'date', 'before:'.($request->input('tanggal_selesai', $semester->tanggal_selesai->toDateString()))],
            'tanggal_selesai' => ['sometimes', 'date', 'after:'.($request->input('tanggal_mulai', $semester->tanggal_mulai->toDateString()))],
            'status' => 'sometimes|in:aktif,nonaktif',
        ]);

        $wasActive = $semester->status === 'aktif';

        DB::transaction(function () use ($request, $semester): void {
            DB::table('tahun_ajarans')->orderBy('id')->lockForUpdate()->get(['id']);
            if ($request->status === 'aktif') {
                Semester::where('status', 'aktif')->where('id', '!=', $semester->id)->update(['status' => 'nonaktif']);
            }
            $semester->update($request->only(['tahun_ajaran_id', 'nama', 'kode', 'tanggal_mulai', 'tanggal_selesai', 'status']));
        });

        // Jika semester baru diaktifkan, buat alpha_accumulations untuk semua mahasiswa aktif
        if (! $wasActive && $semester->status === 'aktif') {
            $this->createAlphaAccumulations($semester->id);
        }

        return $this->success($semester, 'Semester berhasil diperbarui');
    }

    /**
     * Create alpha_accumulation records for all active mahasiswa when semester is activated
     */
    private function createAlphaAccumulations(int $semesterId): void
    {
        $mahasiswaIds = User::whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('status', 'aktif')
            ->pluck('id');

        foreach ($mahasiswaIds as $mhsId) {
            AlphaAccumulation::firstOrCreate(
                ['user_id' => $mhsId, 'semester_id' => $semesterId],
                [
                    'total_alpha_menit' => 0,
                    'sp_status' => 'aman',
                    'last_calculated_at' => now(),
                ]
            );
        }
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $semester = Semester::findOrFail($id);

        if ($semester->mataKuliahs()->exists()) {
            return $this->error('Tidak dapat menghapus semester yang memiliki mata kuliah', 422);
        }

        // M-19: semester juga menjadi anchor rekam disipliner/akumulasi alpha.
        // Cegah hard delete master yang masih menyimpan sejarah akademik agar
        // konsisten dengan RESTRICT di level database.
        if (SpRecord::where('semester_id', $semester->id)->exists()
            || AlphaAccumulation::where('semester_id', $semester->id)->exists()) {
            return $this->error('Tidak dapat menghapus semester yang memiliki riwayat SP atau akumulasi alpha', 422);
        }

        $semester->delete();

        return $this->success(message: 'Semester berhasil dihapus');
    }
}
