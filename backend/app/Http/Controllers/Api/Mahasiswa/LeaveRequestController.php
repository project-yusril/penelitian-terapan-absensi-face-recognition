<?php

namespace App\Http\Controllers\Api\Mahasiswa;

use App\Http\Controllers\Controller;
use App\Models\Jadwal;
use App\Models\LeaveRequest;
use App\Models\MataKuliah;
use App\Models\User;
use App\Services\PrivateFileUrlService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LeaveRequestController extends Controller
{
    private const SKIP_DUPLIKAT = 'duplikat';

    private const SKIP_TANPA_JADWAL = 'tanpa_jadwal';

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $query = LeaveRequest::with('mataKuliah')
            ->where('user_id', $user->id);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $data = $query->orderByDesc('created_at')->paginate($this->resolvePerPage($request));
        $data->getCollection()->each(function (LeaveRequest $item): void {
            $item->file_surat_url = app(PrivateFileUrlService::class)->leaveDocument($item);
        });

        return $this->paginated($data);
    }

    /**
     * Dua mode:
     * - single: `mata_kuliah_id` (jalur lama, response tetap satu objek LeaveRequest).
     * - multi: `all_mata_kuliah=true` atau `mata_kuliah_ids[]`, satu izin per MK enrolled
     *   yang punya pertemuan pada rentang tanggal. Model data tetap per-MK sehingga
     *   alpha/SP/rekap tidak berubah.
     */
    public function store(Request $request): JsonResponse
    {
        $multi = $this->wantsMultiCourse($request);

        $request->validate([
            'mata_kuliah_id' => [Rule::requiredIf(! $multi), 'nullable', 'exists:mata_kuliahs,id'],
            'all_mata_kuliah' => 'nullable|boolean',
            'mata_kuliah_ids' => 'nullable|array|min:1',
            'mata_kuliah_ids.*' => 'integer|distinct|exists:mata_kuliahs,id',
            'jenis' => 'required|in:izin,sakit',
            'tanggal_mulai' => 'required|date|after_or_equal:today',
            'tanggal_selesai' => 'required|date|after_or_equal:tanggal_mulai',
            'keterangan' => 'required|string|max:500',
            'file_surat' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $user = $request->user();
        $mulai = Carbon::parse($request->tanggal_mulai)->startOfDay();
        $selesai = Carbon::parse($request->tanggal_selesai)->startOfDay();

        $enrolled = $user->mataKuliahs()->pluck('mata_kuliahs.id')->map(fn ($id) => (int) $id);
        $eligible = $this->eligibleCourseIds($user, $mulai, $selesai);
        $requested = $this->requestedCourseIds($request, $multi, $multi ? $eligible : $enrolled);

        if ($requested->diff($enrolled)->isNotEmpty()) {
            return $this->error('Anda tidak terdaftar di mata kuliah ini', 403);
        }
        if ($multi && $requested->diff($eligible)->isNotEmpty()) {
            return $this->error('Mata kuliah tidak aktif pada periode pengajuan', 422);
        }
        if ($requested->isEmpty()) {
            return $this->error('Tidak ada mata kuliah yang bisa diajukan izin', 422);
        }

        $names = MataKuliah::whereIn('id', $requested)->pluck('nama', 'id');
        $skipped = collect();
        $targets = $requested;

        // Fan-out tidak boleh membuat izin untuk MK tanpa pertemuan pada rentang.
        // Jalur single-MK tetap apa adanya supaya klien lama tidak berubah perilaku.
        if ($multi) {
            $terjadwal = $this->courseIdsWithScheduleInRange($requested, $mulai, $selesai);
            $skipped = $this->skipEntries($requested->diff($terjadwal), $names, self::SKIP_TANPA_JADWAL);
            $targets = $requested->intersect($terjadwal)->values();
        }

        $duplikat = $this->courseIdsWithActiveLeave($user->id, $targets, $mulai, $selesai);
        $skipped = $skipped->concat($this->skipEntries($duplikat, $names, self::SKIP_DUPLIKAT));
        $targets = $targets->diff($duplikat)->values();

        if ($targets->isEmpty()) {
            return $this->error(
                $multi
                    ? 'Tidak ada izin yang dibuat: semua mata kuliah sudah punya izin aktif atau tidak punya jadwal pada rentang tanggal'
                    : 'Anda sudah mengajukan izin untuk mata kuliah ini pada tanggal tersebut',
                422,
                $multi ? ['skipped' => $skipped->values()->all()] : null,
            );
        }

        // Satu file surat dipakai bersama seluruh baris; dihapus bila transaksi gagal
        // atau bila pada akhirnya tidak ada baris yang dibuat.
        $filePath = null;
        if ($request->hasFile('file_surat')) {
            $filePath = $request->file('file_surat')->store('leave-requests', 'documents');
        }

        try {
            [$createdIds, $balapan] = DB::transaction(function () use ($user, $targets, $request, $mulai, $selesai, $filePath): array {
                User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                $createdIds = collect();
                // Cek ulang di dalam lock: submit paralel tidak boleh menghasilkan izin ganda.
                $balapan = $this->courseIdsWithActiveLeave($user->id, $targets, $mulai, $selesai);

                foreach ($targets->diff($balapan) as $mataKuliahId) {

                    $createdIds->push(LeaveRequest::create([
                        'user_id' => $user->id,
                        'mata_kuliah_id' => $mataKuliahId,
                        'jenis' => $request->jenis,
                        'tanggal_mulai' => $mulai->toDateString(),
                        'tanggal_selesai' => $selesai->toDateString(),
                        'keterangan' => $request->keterangan,
                        'file_surat' => $filePath,
                        'status' => 'pending',
                    ])->id);
                }

                return [$createdIds, $balapan];
            });
        } catch (\Throwable $exception) {
            if ($filePath) {
                Storage::disk('documents')->delete($filePath);
            }
            throw $exception;
        }

        $skipped = $skipped->concat($this->skipEntries($balapan, $names, self::SKIP_DUPLIKAT));

        if ($createdIds->isEmpty()) {
            if ($filePath) {
                Storage::disk('documents')->delete($filePath);
            }

            // Semua target tersaring cek-ulang di dalam lock (submit paralel). Respons
            // multi tetap membawa daftar `skipped` agar konsisten dengan cabang di atas.
            return $this->error(
                $multi
                    ? 'Tidak ada izin yang dibuat: semua mata kuliah sudah punya izin aktif atau tidak punya jadwal pada rentang tanggal'
                    : 'Anda sudah mengajukan izin untuk mata kuliah ini pada tanggal tersebut',
                422,
                $multi ? ['skipped' => $skipped->values()->all()] : null,
            );
        }

        $created = LeaveRequest::with('mataKuliah')->whereIn('id', $createdIds)->orderBy('id')->get();

        if (! $multi) {
            return $this->created($created->first(), 'Pengajuan izin berhasil disubmit');
        }

        return $this->created([
            'created_count' => $created->count(),
            'leave_requests' => $created,
            'skipped' => $skipped->values(),
        ], "Pengajuan izin dibuat untuk {$created->count()} mata kuliah");
    }

    private function wantsMultiCourse(Request $request): bool
    {
        return $request->boolean('all_mata_kuliah') || $request->filled('mata_kuliah_ids');
    }

    /**
     * @param  Collection<int, int>  $enrolled
     * @return Collection<int, int>
     */
    private function requestedCourseIds(Request $request, bool $multi, Collection $enrolled): Collection
    {
        if (! $multi) {
            return collect([(int) $request->input('mata_kuliah_id')]);
        }

        if ($request->filled('mata_kuliah_ids')) {
            return collect($request->input('mata_kuliah_ids'))->map(fn ($id) => (int) $id)->unique()->values();
        }

        return $enrolled->values();
    }

    /**
     * MK yang punya minimal satu jadwal aktif pada hari-hari yang tercakup rentang.
     *
     * @param  Collection<int, int>  $courseIds
     * @return Collection<int, int>
     */
    private function courseIdsWithScheduleInRange(Collection $courseIds, Carbon $mulai, Carbon $selesai): Collection
    {
        return Jadwal::whereIn('mata_kuliah_id', $courseIds)
            ->where('status', 'aktif')
            ->whereIn('hari', $this->dayNamesInRange($mulai, $selesai))
            ->distinct()
            ->pluck('mata_kuliah_id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * MK KRS aktif yang periode semester dan tahun ajarannya mencakup seluruh
     * rentang pengajuan. Ini mencegah fan-out ke enrollment historis.
     *
     * @return Collection<int, int>
     */
    private function eligibleCourseIds(User $user, Carbon $mulai, Carbon $selesai): Collection
    {
        return $user->mataKuliahs()
            ->where('mata_kuliahs.status', 'aktif')
            ->whereHas('semester', fn ($query) => $query
                ->where('status', 'aktif')
                ->whereDate('tanggal_mulai', '<=', $mulai->toDateString())
                ->whereDate('tanggal_selesai', '>=', $selesai->toDateString())
                ->whereHas('tahunAjaran', fn ($year) => $year
                    ->where('status', 'aktif')
                    ->whereDate('tanggal_mulai', '<=', $mulai->toDateString())
                    ->whereDate('tanggal_selesai', '>=', $selesai->toDateString())))
            ->pluck('mata_kuliahs.id')
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    /**
     * MK yang sudah punya izin aktif (pending/approved) dengan rentang tanggal yang
     * beririsan dengan `mulai..selesai`. Overlap dipakai (bukan sekadar tanggal_mulai
     * sama) supaya izin multi-hari yang bertumpuk tidak lolos dan menimpa attendance
     * yang sama saat materialisasi.
     *
     * @param  Collection<int, int>  $courseIds
     * @return Collection<int, int>
     */
    private function courseIdsWithActiveLeave(int $userId, Collection $courseIds, Carbon $mulai, Carbon $selesai): Collection
    {
        if ($courseIds->isEmpty()) {
            return collect();
        }

        return LeaveRequest::where('user_id', $userId)
            ->whereIn('mata_kuliah_id', $courseIds)
            ->where('tanggal_mulai', '<=', $selesai->toDateString())
            ->where('tanggal_selesai', '>=', $mulai->toDateString())
            ->whereIn('status', ['pending', 'approved'])
            ->pluck('mata_kuliah_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }

    /**
     * Nama hari mengikuti format `hari` pada `jadwals` (locale id, mis. "Senin").
     *
     * @return array<int, string>
     */
    private function dayNamesInRange(Carbon $mulai, Carbon $selesai): array
    {
        $days = [];
        for ($date = $mulai->copy(); $date->lte($selesai) && count($days) < 7; $date->addDay()) {
            $days[$date->locale('id')->isoFormat('dddd')] = true;
        }

        return array_keys($days);
    }

    /**
     * @param  Collection<int, int>  $courseIds
     * @param  Collection<int, string>  $names
     * @return Collection<int, array<string, mixed>>
     */
    private function skipEntries(Collection $courseIds, Collection $names, string $alasan): Collection
    {
        $pesan = $alasan === self::SKIP_DUPLIKAT
            ? 'Sudah ada izin aktif pada tanggal tersebut'
            : 'Tidak ada jadwal aktif pada rentang tanggal';

        return $courseIds->values()->map(fn (int $id): array => [
            'mata_kuliah_id' => $id,
            'nama' => $names[$id] ?? null,
            'alasan' => $alasan,
            'pesan' => $pesan,
        ]);
    }
}
