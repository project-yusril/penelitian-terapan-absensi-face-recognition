<?php

namespace App\Http\Controllers\Api\Dosen;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\MataKuliah;
use App\Services\AttendanceWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AttendanceController extends Controller
{
    /**
     * List attendance (pending approvals & recent)
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $mkIds = MataKuliah::where('dosen_id', $user->id)->pluck('id');

        $query = Attendance::with(['user:id,nama,nim,kelas', 'mataKuliah:id,kode_mk,nama,kelas', 'jadwal'])
            ->whereIn('mata_kuliah_id', $mkIds);

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('mata_kuliah_id')) {
            $query->where('mata_kuliah_id', $request->mata_kuliah_id);
        }

        if ($request->filled('tanggal')) {
            $query->whereDate('tanggal', $request->tanggal);
        }

        $data = $query->orderByDesc('tanggal')->orderByDesc('checkin_time')
            ->paginate($this->resolvePerPage($request, 20));

        return $this->paginated($data);
    }

    /**
     * Approve pending attendance → hadir_terlambat
     */
    public function approve(Request $request, int $id, AttendanceWorkflowService $workflow): JsonResponse
    {
        $user = $request->user();
        $mkIds = MataKuliah::where('dosen_id', $user->id)->pluck('id');

        $attendance = Attendance::whereIn('mata_kuliah_id', $mkIds)
            ->findOrFail($id);

        $oldStatus = $attendance->status;
        $attendance = $workflow->approvePending($user, $attendance->id, $request, 'Disetujui oleh dosen');

        return $this->success([
            'attendance' => $attendance->fresh()->load('user:id,nama,nim'),
            'old_status' => $oldStatus,
            'new_status' => $attendance->status,
        ], 'Attendance berhasil di-approve');
    }

    /**
     * Reject pending attendance → alpha
     */
    public function reject(Request $request, int $id, AttendanceWorkflowService $workflow): JsonResponse
    {
        $request->validate([
            'alasan' => 'nullable|string|max:500',
        ]);

        $user = $request->user();
        $mkIds = MataKuliah::where('dosen_id', $user->id)->pluck('id');

        $attendance = Attendance::whereIn('mata_kuliah_id', $mkIds)
            ->findOrFail($id);

        $oldStatus = $attendance->status;
        $attendance = $workflow->rejectPending($user, $attendance->id, $request->alasan, $request, 'Ditolak oleh dosen');

        return $this->success([
            'attendance' => $attendance->fresh()->load('user:id,nama,nim'),
            'old_status' => $oldStatus,
            'new_status' => 'alpha',
        ], 'Attendance ditolak');
    }

    /**
     * Siapa yang sudah hadir di kelas hari ini (real-time)
     */
    public function classToday(Request $request): JsonResponse
    {
        $user = $request->user();
        $mkIds = MataKuliah::where('dosen_id', $user->id)->pluck('id');

        $query = Attendance::with(['user:id,nama,nim,kelas', 'mataKuliah:id,kode_mk,nama,kelas'])
            ->whereIn('mata_kuliah_id', $mkIds)
            ->whereDate('tanggal', today());

        if ($request->filled('mata_kuliah_id')) {
            $query->where('mata_kuliah_id', $request->mata_kuliah_id);
        }

        $attendances = $query->orderBy('checkin_time')->get();

        $summary = [
            'hadir' => $attendances->where('status', 'hadir')->count(),
            'hadir_terlambat' => $attendances->where('status', 'hadir_terlambat')->count(),
            'pending' => $attendances->where('status', 'pending')->count(),
            'alpha' => $attendances->where('status', 'alpha')->count(),
            'total' => $attendances->count(),
        ];

        return $this->success([
            'tanggal' => today()->toDateString(),
            'summary' => $summary,
            'attendances' => $attendances,
        ]);
    }

    /**
     * Rekap kehadiran per mata kuliah
     */
    public function rekap(Request $request, int $mataKuliahId): JsonResponse
    {
        $user = $request->user();

        $mk = MataKuliah::where('id', $mataKuliahId)
            ->where('dosen_id', $user->id)
            ->firstOrFail();

        $mahasiswas = $mk->mahasiswas()
            ->select('users.id', 'users.nama', 'users.nim', 'users.kelas')
            ->get();

        $rekap = $mahasiswas->map(function ($mhs) use ($mk) {
            $stats = Attendance::where('user_id', $mhs->id)
                ->where('mata_kuliah_id', $mk->id)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                    SUM(CASE WHEN status = 'hadir_terlambat' THEN 1 ELSE 0 END) as terlambat,
                    SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha,
                    SUM(CASE WHEN status IN ('izin', 'sakit') THEN 1 ELSE 0 END) as izin_sakit
                ")
                ->first();

            return [
                'id' => $mhs->id,
                'nama' => $mhs->nama,
                'nim' => $mhs->nim,
                'kelas' => $mhs->kelas,
                'total' => $stats->total ?? 0,
                'hadir' => $stats->hadir ?? 0,
                'terlambat' => $stats->terlambat ?? 0,
                'alpha' => $stats->alpha ?? 0,
                'izin_sakit' => $stats->izin_sakit ?? 0,
                'persentase' => $stats->total > 0
                    ? round((($stats->hadir + $stats->terlambat) / $stats->total) * 100, 1)
                    : 0,
            ];
        });

        return $this->success([
            'mata_kuliah' => $mk->only(['id', 'kode_mk', 'nama', 'kelas']),
            'total_pertemuan' => $mk->total_pertemuan,
            'rekap' => $rekap,
        ]);
    }

    /**
     * Override status kehadiran
     */
    public function override(Request $request, int $id, AttendanceWorkflowService $workflow): JsonResponse
    {
        $request->validate([
            'status' => 'required|in:hadir,hadir_terlambat,alpha,izin,sakit',
            'alasan' => 'required|string|max:500',
        ]);

        $user = $request->user();
        $mkIds = MataKuliah::where('dosen_id', $user->id)->pluck('id');

        $attendance = Attendance::whereIn('mata_kuliah_id', $mkIds)
            ->findOrFail($id);

        $oldStatus = $attendance->status;

        $attendance = $workflow->override($user, $attendance->id, $request->status, $request->alasan, $request);

        return $this->success([
            'attendance' => $attendance->fresh(),
            'old_status' => $oldStatus,
            'new_status' => $request->status,
        ], 'Status kehadiran berhasil diubah');
    }
}
