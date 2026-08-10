<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\MataKuliah;
use App\Services\AttendanceWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Modul Dosen (web): approve/reject pending, override manual, rekap per MK.
 * Super admin diizinkan akses lintas-MK untuk keperluan administrasi.
 */
class DosenAttendanceController extends Controller
{
    private function dosenMkIds(Request $request)
    {
        $user = $request->user();
        if ($user->hasRole('super_admin')) {
            return MataKuliah::pluck('id');
        }

        return MataKuliah::where('dosen_id', $user->id)->pluck('id');
    }

    public function index(Request $request): Response
    {
        $mkIds = $this->dosenMkIds($request);
        $status = $request->string('status', 'pending')->toString();
        $perPage = $this->resolvePerPage($request, 10);

        $items = Attendance::with(['user:id,nama,nim,kelas', 'mataKuliah:id,kode_mk,nama,kelas', 'jadwal'])
            ->whereIn('mata_kuliah_id', $mkIds)
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($request->filled('mata_kuliah_id'), fn ($q) => $q->where('mata_kuliah_id', $request->mata_kuliah_id))
            ->when($request->filled('search'), fn ($q) => $q->whereHas('user', fn ($u) => $u->where('nama', 'like', "%{$request->search}%")->orWhere('nim', 'like', "%{$request->search}%")))
            ->orderByDesc('tanggal')->orderByDesc('checkin_time')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Attendance $a) => [
                'id' => $a->id,
                'nama' => $a->user?->nama,
                'nim' => $a->user?->nim,
                'mata_kuliah' => $a->mataKuliah?->nama,
                'kelas' => $a->mataKuliah?->kelas,
                'tanggal' => $a->tanggal?->format('d M Y'),
                'checkin_time' => $a->checkin_time ? Carbon::parse($a->checkin_time)->format('H:i') : null,
                'status' => $a->status,
                'is_overridden' => (bool) $a->is_overridden,
            ]);

        return Inertia::render('Dosen/Attendance', [
            'items' => $items,
            'filters' => [
                'search' => $request->search,
                'status' => $status,
                'mata_kuliah_id' => $request->integer('mata_kuliah_id') ?: '',
                'per_page' => $perPage,
            ],
            'mataKuliahs' => MataKuliah::whereIn('id', $mkIds)->select('id', 'kode_mk', 'nama')->get(),
        ]);
    }

    /**
     * Rekap kehadiran per mata kuliah yang diampu dosen (FR-REKAP-006).
     * Menampilkan ringkasan per MK + (opsional) detail mahasiswa bila MK dipilih.
     */
    public function rekap(Request $request): Response
    {
        $mkIds = $this->dosenMkIds($request);
        $selectedMkId = $request->integer('mata_kuliah_id') ?: null;

        // Ringkasan agregat per MK.
        $summary = MataKuliah::whereIn('id', $mkIds)
            ->select('id', 'kode_mk', 'nama', 'kelas', 'total_pertemuan')
            ->withCount('mahasiswas')
            ->orderBy('kode_mk')
            ->get()
            ->map(function (MataKuliah $mk) {
                $stats = Attendance::where('mata_kuliah_id', $mk->id)
                    ->selectRaw("
                        COUNT(*) as total,
                        SUM(CASE WHEN status IN ('hadir','hadir_terlambat') THEN 1 ELSE 0 END) as hadir,
                        SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending
                    ")->first();
                $total = (int) ($stats->total ?? 0);

                return [
                    'id' => $mk->id,
                    'kode_mk' => $mk->kode_mk,
                    'nama' => $mk->nama,
                    'kelas' => $mk->kelas,
                    'peserta' => $mk->mahasiswas_count,
                    'total_absensi' => $total,
                    'alpha' => (int) ($stats->alpha ?? 0),
                    'pending' => (int) ($stats->pending ?? 0),
                    'persentase' => $total > 0 ? round(($stats->hadir ?? 0) / $total * 100, 1) : 0,
                ];
            });

        // Detail per mahasiswa bila satu MK dipilih.
        $detail = null;
        if ($selectedMkId && $mkIds->contains($selectedMkId)) {
            $mk = MataKuliah::with('mahasiswas:id,nama,nim,kelas')->find($selectedMkId);
            $detail = [
                'mata_kuliah' => "{$mk->kode_mk} — {$mk->nama}",
                'total_pertemuan' => $mk->total_pertemuan,
                'rows' => $mk->mahasiswas->map(function ($mhs) use ($mk) {
                    $stats = Attendance::where('user_id', $mhs->id)
                        ->where('mata_kuliah_id', $mk->id)
                        ->selectRaw("
                            COUNT(*) as total,
                            SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir,
                            SUM(CASE WHEN status = 'hadir_terlambat' THEN 1 ELSE 0 END) as terlambat,
                            SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha,
                            SUM(CASE WHEN status IN ('izin','sakit') THEN 1 ELSE 0 END) as izin_sakit
                        ")->first();
                    $total = (int) ($stats->total ?? 0);
                    $hadirEf = (int) ($stats->hadir ?? 0) + (int) ($stats->terlambat ?? 0);

                    return [
                        'nama' => $mhs->nama,
                        'nim' => $mhs->nim,
                        'kelas' => $mhs->kelas,
                        'hadir' => (int) ($stats->hadir ?? 0),
                        'terlambat' => (int) ($stats->terlambat ?? 0),
                        'alpha' => (int) ($stats->alpha ?? 0),
                        'izin_sakit' => (int) ($stats->izin_sakit ?? 0),
                        'persentase' => $total > 0 ? round($hadirEf / $total * 100, 1) : 0,
                    ];
                })->values(),
            ];
        }

        return Inertia::render('Dosen/Rekap', [
            'summary' => $summary,
            'detail' => $detail,
            'selectedMkId' => $selectedMkId,
        ]);
    }

    public function approve(Request $request, Attendance $attendance, AttendanceWorkflowService $workflow): RedirectResponse
    {
        $this->authorizeMk($request, $attendance);
        if ($attendance->status !== 'pending') {
            return back()->with('error', 'Hanya status pending yang bisa disetujui.');
        }
        $approved = $workflow->approvePending($request->user(), $attendance->id, $request, 'Disetujui via dashboard');

        return back()->with('success', $approved->status === 'hadir'
            ? 'Kehadiran disetujui (hadir).'
            : 'Kehadiran disetujui (hadir terlambat).');
    }

    public function reject(Request $request, Attendance $attendance, AttendanceWorkflowService $workflow): RedirectResponse
    {
        $request->validate(['alasan' => ['nullable', 'string', 'max:500']]);
        $this->authorizeMk($request, $attendance);
        if ($attendance->status !== 'pending') {
            return back()->with('error', 'Hanya status pending yang bisa ditolak.');
        }
        $workflow->rejectPending($request->user(), $attendance->id, $request->alasan, $request, 'Ditolak via dashboard');

        return back()->with('success', 'Kehadiran ditolak (alpha).');
    }

    public function override(Request $request, Attendance $attendance, AttendanceWorkflowService $workflow): RedirectResponse
    {
        $data = $request->validate([
            'status' => ['required', Rule::in(['hadir', 'hadir_terlambat', 'alpha', 'izin', 'sakit'])],
            'alasan' => ['required', 'string', 'max:500'],
        ]);
        $this->authorizeMk($request, $attendance);

        $workflow->override($request->user(), $attendance->id, $data['status'], $data['alasan'], $request);

        return back()->with('success', 'Status kehadiran berhasil di-override.');
    }

    private function authorizeMk(Request $request, Attendance $attendance): void
    {
        abort_unless($this->dosenMkIds($request)->contains($attendance->mata_kuliah_id), 403);
    }
}
