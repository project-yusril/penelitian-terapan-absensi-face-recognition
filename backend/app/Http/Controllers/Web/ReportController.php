<?php

namespace App\Http\Controllers\Web;

use App\Exports\AttendanceExport;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\User;
use App\Services\AuthorizationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Laporan & rekapitulasi kehadiran (PRD FR-REKAP-005..008).
 * Rekap per mata kuliah & per prodi dengan ringkasan persentase kehadiran.
 */
class ReportController extends Controller
{
    public function __construct(private AuthorizationService $authorization) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $tab = $request->string('tab', 'mata_kuliah')->toString();
        $prodiId = $request->integer('prodi_id') ?: null;
        $mataKuliahId = $request->integer('mata_kuliah_id');
        $userId = $request->integer('user_id');

        $report = null;

        if ($tab === 'mata_kuliah' && $mataKuliahId) {
            $report = $this->byMataKuliah($user, $mataKuliahId);
        } elseif ($tab === 'prodi') {
            $report = $this->byProdi($user, $prodiId);
        } elseif ($tab === 'mahasiswa' && $userId) {
            $report = $this->byMahasiswa($user, $userId);
        }

        return Inertia::render('Reports/Index', [
            'tab' => $tab,
            'filters' => ['prodi_id' => $prodiId, 'mata_kuliah_id' => $mataKuliahId, 'user_id' => $userId],
            'prodis' => $this->authorization->scopeProdis(Prodi::select('id', 'nama'), $user)->get(),
            'mataKuliahs' => $this->authorization->scopeMataKuliahs(MataKuliah::select('id', 'kode_mk', 'nama', 'prodi_id'), $user)
                ->when($prodiId, fn ($q) => $q->where('prodi_id', $prodiId))
                ->orderBy('kode_mk')->get(),
            'mahasiswas' => $this->authorization->scopeUsers(User::select('id', 'nama', 'nim', 'prodi_id'), $user)
                ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
                ->when($prodiId, fn ($q) => $q->where('prodi_id', $prodiId))
                ->orderBy('nama')->get(),
            'report' => $report,
        ]);
    }

    /**
     * Export rekap kehadiran ke Excel (XLSX) — memakai AttendanceExport.
     */
    public function exportExcel(Request $request): StreamedResponse
    {
        $user = $request->user();
        // M-16: export harus memakai filter yang sama dengan tampilan layar,
        // termasuk semester, kelas, dan tab yang sedang aktif.
        $tab = $request->string('tab', 'mata_kuliah')->toString();
        $prodiId = $request->integer('prodi_id') ?: null;
        $semesterId = $request->integer('semester_id') ?: null;
        $kelas = $request->string('kelas')->toString() ?: null;
        $mataKuliahId = $request->integer('mata_kuliah_id') ?: null;
        $userId = $request->integer('user_id') ?: null;

        // Filter mata kuliah hanya relevan pada tab mata_kuliah, dan filter
        // mahasiswa hanya pada tab mahasiswa.
        if ($tab !== 'mata_kuliah') {
            $mataKuliahId = null;
        }
        if ($tab !== 'mahasiswa') {
            $userId = null;
        }

        $export = new AttendanceExport($user, $semesterId, $prodiId, $kelas, $mataKuliahId, $userId);
        $path = $export->generate();

        return response()->streamDownload(function () use ($path) {
            readfile($path);
            @unlink($path);
        }, 'rekap_kehadiran_'.now()->format('Ymd_His').'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Export rekap (mata kuliah / prodi / mahasiswa) ke PDF.
     */
    public function exportPdf(Request $request)
    {
        $user = $request->user();

        $tab = $request->string('tab', 'mata_kuliah')->toString();
        $prodiId = $request->integer('prodi_id') ?: null;
        $mataKuliahId = $request->integer('mata_kuliah_id');
        $userId = $request->integer('user_id');

        $report = match (true) {
            $tab === 'mata_kuliah' && $mataKuliahId => $this->byMataKuliah($user, $mataKuliahId),
            $tab === 'mahasiswa' && $userId => $this->byMahasiswa($user, $userId),
            default => $this->byProdi($user, $prodiId),
        };

        $pdf = Pdf::loadView('reports.rekap', [
            'report' => $report,
            'generatedAt' => now()->format('d M Y H:i'),
        ]);

        return $pdf->download('rekap_'.$tab.'_'.now()->format('Ymd_His').'.pdf');
    }

    private function byMataKuliah(User $actor, int $mkId): array
    {
        $mk = $this->authorization->scopeMataKuliahs(
            MataKuliah::with('mahasiswas:id,nama,nim,kelas'),
            $actor,
        )->findOrFail($mkId);

        $rows = $mk->mahasiswas->map(function ($mhs) use ($actor, $mk) {
            $stats = $this->authorization->scopeAttendances(Attendance::query(), $actor)
                ->where('user_id', $mhs->id)
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
                'total' => $total,
                'hadir' => (int) ($stats->hadir ?? 0),
                'terlambat' => (int) ($stats->terlambat ?? 0),
                'alpha' => (int) ($stats->alpha ?? 0),
                'izin_sakit' => (int) ($stats->izin_sakit ?? 0),
                'persentase' => $total > 0 ? round($hadirEf / $total * 100, 1) : 0,
            ];
        })->values();

        return [
            'type' => 'mata_kuliah',
            'title' => "{$mk->kode_mk} — {$mk->nama}",
            'total_pertemuan' => $mk->total_pertemuan,
            'rows' => $rows,
            'avg_kehadiran' => $rows->count() ? round($rows->avg('persentase'), 1) : 0,
        ];
    }

    private function byMahasiswa(User $actor, int $userId): array
    {
        $mhs = $this->authorization->scopeUsers(User::with('prodi:id,nama'), $actor)->findOrFail($userId);

        // Rekap per mata kuliah yang diikuti mahasiswa ini.
        $mataKuliahs = $this->authorization->scopeMataKuliahs(
            MataKuliah::whereHas('mahasiswas', fn ($query) => $query->whereKey($mhs->id)),
            $actor,
        )->get();
        $rows = $mataKuliahs->map(function ($mk) use ($actor, $mhs) {
            $stats = $this->authorization->scopeAttendances(Attendance::query(), $actor)
                ->where('user_id', $mhs->id)
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
                'mata_kuliah' => "{$mk->kode_mk} — {$mk->nama}",
                'total' => $total,
                'hadir' => (int) ($stats->hadir ?? 0),
                'terlambat' => (int) ($stats->terlambat ?? 0),
                'alpha' => (int) ($stats->alpha ?? 0),
                'izin_sakit' => (int) ($stats->izin_sakit ?? 0),
                'persentase' => $total > 0 ? round($hadirEf / $total * 100, 1) : 0,
            ];
        })->values();

        return [
            'type' => 'mahasiswa',
            'title' => "{$mhs->nama} ({$mhs->nim})",
            'subtitle' => $mhs->prodi?->nama,
            'rows' => $rows,
            'avg_kehadiran' => $rows->count() ? round($rows->avg('persentase'), 1) : 0,
        ];
    }

    private function byProdi(User $actor, ?int $prodiId): array
    {
        $prodis = $this->authorization->scopeProdis(Prodi::query(), $actor)
            ->when($prodiId, fn ($q) => $q->where('id', $prodiId))->get();

        $rows = $prodis->map(function ($p) use ($actor) {
            $mhsIds = $p->users()->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))->pluck('users.id');
            $stats = $this->authorization->scopeAttendances(Attendance::query(), $actor)
                ->whereIn('user_id', $mhsIds)
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(CASE WHEN status IN ('hadir','hadir_terlambat') THEN 1 ELSE 0 END) as hadir
                ")->first();
            $total = (int) ($stats->total ?? 0);

            return [
                'prodi' => $p->nama,
                'mahasiswa' => $mhsIds->count(),
                'total_absensi' => $total,
                'persentase' => $total > 0 ? round(($stats->hadir ?? 0) / $total * 100, 1) : 0,
            ];
        })->values();

        return [
            'type' => 'prodi',
            'title' => 'Rekap Kehadiran per Program Studi',
            'rows' => $rows,
        ];
    }
}
