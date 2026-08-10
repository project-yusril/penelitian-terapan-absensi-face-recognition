<?php

namespace App\Http\Controllers\Api\Admin;

use App\Exports\AttendanceExport;
use App\Http\Controllers\Controller;
use App\Models\Attendance;
use App\Models\MataKuliah;
use App\Models\Prodi;
use App\Models\Semester;
use App\Models\User;
use App\Services\AuthorizationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(private AuthorizationService $authorization) {}

    /**
     * Rekap per mahasiswa
     */
    public function byMahasiswa(Request $request): JsonResponse
    {
        $request->validate([
            'user_id' => 'required|exists:users,id',
            'semester_id' => 'nullable|exists:semesters,id',
        ]);

        $semesterId = $request->semester_id ?? Semester::where('status', 'aktif')->value('id');
        $userId = $request->user_id;
        $user = $this->authorization->scopeUsers(User::query(), $request->user())->findOrFail($userId);

        $attendances = $this->authorization->scopeAttendances(Attendance::query(), $request->user())
            ->where('user_id', $userId)
            ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterId))
            ->with('mataKuliah:id,kode_mk,nama')
            ->get();

        $summary = [
            'hadir' => $attendances->where('status', 'hadir')->count(),
            'hadir_terlambat' => $attendances->where('status', 'hadir_terlambat')->count(),
            'izin' => $attendances->where('status', 'izin')->count(),
            'sakit' => $attendances->where('status', 'sakit')->count(),
            'alpha' => $attendances->where('status', 'alpha')->count(),
            'pending' => $attendances->where('status', 'pending')->count(),
            'total' => $attendances->count(),
        ];

        $perMk = $attendances->groupBy('mata_kuliah_id')->map(function ($items) {
            $mk = $items->first()->mataKuliah;

            return [
                'mata_kuliah' => $mk?->only(['id', 'kode_mk', 'nama']),
                'hadir' => $items->where('status', 'hadir')->count(),
                'hadir_terlambat' => $items->where('status', 'hadir_terlambat')->count(),
                'izin' => $items->where('status', 'izin')->count(),
                'sakit' => $items->where('status', 'sakit')->count(),
                'alpha' => $items->where('status', 'alpha')->count(),
                'total' => $items->count(),
            ];
        })->values();

        return $this->success([
            'user' => $user->load('prodi:id,kode,nama')->only(['id', 'nama', 'nim', 'kelas', 'prodi_id', 'prodi']),
            'semester_id' => $semesterId,
            'summary' => $summary,
            'per_mata_kuliah' => $perMk,
        ]);
    }

    /**
     * Rekap per mata kuliah (matrix view)
     */
    public function byMataKuliah(Request $request): JsonResponse
    {
        $request->validate([
            'mata_kuliah_id' => 'required|exists:mata_kuliahs,id',
        ]);

        $mataKuliah = $this->authorization->scopeMataKuliahs(
            MataKuliah::with('dosen:id,nama'),
            $request->user(),
        )->findOrFail($request->mata_kuliah_id);

        $mahasiswas = $mataKuliah->mahasiswas()->select('users.id', 'users.nama', 'users.nim', 'users.kelas')->get();

        $matrix = $mahasiswas->map(function ($mhs) use ($mataKuliah, $request) {
            $attendances = $this->authorization->scopeAttendances(Attendance::query(), $request->user())
                ->where('user_id', $mhs->id)
                ->where('mata_kuliah_id', $mataKuliah->id)
                ->orderBy('pertemuan_ke')
                ->get(['pertemuan_ke', 'tanggal', 'status']);

            return [
                'mahasiswa' => $mhs->only(['id', 'nama', 'nim', 'kelas']),
                'attendances' => $attendances,
                'summary' => [
                    'hadir' => $attendances->where('status', 'hadir')->count(),
                    'hadir_terlambat' => $attendances->where('status', 'hadir_terlambat')->count(),
                    'alpha' => $attendances->where('status', 'alpha')->count(),
                    'izin' => $attendances->whereIn('status', ['izin', 'sakit'])->count(),
                    'total' => $attendances->count(),
                ],
            ];
        });

        return $this->success([
            'mata_kuliah' => $mataKuliah->only(['id', 'kode_mk', 'nama', 'kelas', 'sks']),
            'dosen' => $mataKuliah->dosen?->only(['id', 'nama']),
            'data' => $matrix,
        ]);
    }

    /**
     * Rekap per kelas
     */
    public function byKelas(Request $request): JsonResponse
    {
        $request->validate([
            'kelas' => 'required|string',
            'semester_id' => 'nullable|exists:semesters,id',
            'prodi_id' => 'nullable|exists:prodis,id',
        ]);

        $semesterId = $request->semester_id ?? Semester::where('status', 'aktif')->value('id');

        $query = $this->authorization->scopeUsers(User::query(), $request->user())
            ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('kelas', $request->kelas)
            ->where('status', 'aktif');

        if ($request->filled('prodi_id')) {
            $query->where('prodi_id', $request->prodi_id);
        }

        $mahasiswas = $query->select('id', 'nama', 'nim', 'kelas', 'prodi_id')->get();

        $data = $mahasiswas->map(function ($mhs) use ($request, $semesterId) {
            $attendances = $this->authorization->scopeAttendances(Attendance::query(), $request->user())
                ->where('user_id', $mhs->id)
                ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterId))
                ->get();

            return [
                'mahasiswa' => $mhs->only(['id', 'nama', 'nim']),
                'hadir' => $attendances->where('status', 'hadir')->count(),
                'hadir_terlambat' => $attendances->where('status', 'hadir_terlambat')->count(),
                'alpha' => $attendances->where('status', 'alpha')->count(),
                'izin' => $attendances->whereIn('status', ['izin', 'sakit'])->count(),
                'total' => $attendances->count(),
                'persentase_hadir' => $attendances->count() > 0
                    ? round(($attendances->whereIn('status', ['hadir', 'hadir_terlambat'])->count() / $attendances->count()) * 100, 1)
                    : 0,
            ];
        });

        return $this->success([
            'kelas' => $request->kelas,
            'semester_id' => $semesterId,
            'data' => $data,
        ]);
    }

    /**
     * Rekap per prodi
     */
    public function byProdi(Request $request): JsonResponse
    {
        $request->validate([
            'prodi_id' => 'required|exists:prodis,id',
            'semester_id' => 'nullable|exists:semesters,id',
        ]);

        $semesterId = $request->semester_id ?? Semester::where('status', 'aktif')->value('id');
        $prodi = $this->authorization->scopeProdis(Prodi::query(), $request->user())
            ->findOrFail($request->prodi_id);

        $mahasiswas = $this->authorization->scopeUsers(User::query(), $request->user())
            ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
            ->where('prodi_id', $prodi->id)
            ->where('status', 'aktif')
            ->select('id', 'nama', 'nim', 'kelas')
            ->get();

        $totalHadir = 0;
        $totalAlpha = 0;
        $totalRecords = 0;

        $perKelas = $mahasiswas->groupBy('kelas')->map(function ($kelasGroup) use ($request, $semesterId, &$totalHadir, &$totalAlpha, &$totalRecords) {
            $kelasHadir = 0;
            $kelasAlpha = 0;
            $kelasTotal = 0;

            foreach ($kelasGroup as $mhs) {
                $attendances = $this->authorization->scopeAttendances(Attendance::query(), $request->user())
                    ->where('user_id', $mhs->id)
                    ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterId))
                    ->get();

                $kelasHadir += $attendances->whereIn('status', ['hadir', 'hadir_terlambat'])->count();
                $kelasAlpha += $attendances->where('status', 'alpha')->count();
                $kelasTotal += $attendances->count();
            }

            $totalHadir += $kelasHadir;
            $totalAlpha += $kelasAlpha;
            $totalRecords += $kelasTotal;

            return [
                'jumlah_mahasiswa' => $kelasGroup->count(),
                'total_hadir' => $kelasHadir,
                'total_alpha' => $kelasAlpha,
                'total_records' => $kelasTotal,
                'persentase_hadir' => $kelasTotal > 0 ? round(($kelasHadir / $kelasTotal) * 100, 1) : 0,
            ];
        });

        return $this->success([
            'prodi_id' => $prodi->id,
            'semester_id' => $semesterId,
            'summary' => [
                'total_mahasiswa' => $mahasiswas->count(),
                'total_hadir' => $totalHadir,
                'total_alpha' => $totalAlpha,
                'total_records' => $totalRecords,
                'persentase_hadir' => $totalRecords > 0 ? round(($totalHadir / $totalRecords) * 100, 1) : 0,
            ],
            'per_kelas' => $perKelas,
        ]);
    }

    /**
     * Export rekap ke PDF
     */
    public function exportPdf(Request $request)
    {
        $request->validate([
            'mata_kuliah_id' => 'required|exists:mata_kuliahs,id',
        ]);

        $mataKuliah = $this->authorization->scopeMataKuliahs(
            MataKuliah::with(['dosen:id,nama', 'semester.tahunAjaran', 'prodi:id,kode,nama']),
            $request->user(),
        )->findOrFail($request->mata_kuliah_id);

        $mahasiswas = $mataKuliah->mahasiswas()->select('users.id', 'users.nama', 'users.nim', 'users.kelas')->get();

        $data = $mahasiswas->map(function ($mhs) use ($mataKuliah, $request) {
            $attendances = $this->authorization->scopeAttendances(Attendance::query(), $request->user())
                ->where('user_id', $mhs->id)
                ->where('mata_kuliah_id', $mataKuliah->id)
                ->orderBy('pertemuan_ke')
                ->get();

            return [
                'mahasiswa' => $mhs,
                'attendances' => $attendances,
                'hadir' => $attendances->whereIn('status', ['hadir', 'hadir_terlambat'])->count(),
                'alpha' => $attendances->where('status', 'alpha')->count(),
                'izin' => $attendances->whereIn('status', ['izin', 'sakit'])->count(),
                'total' => $attendances->count(),
            ];
        });

        $pdf = Pdf::loadView('pdf.rekap-kehadiran', [
            'mata_kuliah' => $mataKuliah,
            'data' => $data,
            'tanggal' => now()->locale('id')->isoFormat('D MMMM Y'),
        ]);

        $pdf->setPaper('A4', 'landscape');

        return $pdf->download("Rekap_Kehadiran_{$mataKuliah->kode_mk}_{$mataKuliah->kelas}.pdf");
    }

    /**
     * Export rekap ke Excel (XLSX)
     */
    public function exportExcel(Request $request)
    {
        $request->validate([
            'semester_id' => 'nullable|exists:semesters,id',
            'prodi_id' => 'nullable|exists:prodis,id',
            'kelas' => 'nullable|string',
            'mata_kuliah_id' => 'nullable|exists:mata_kuliahs,id',
        ]);

        $export = new AttendanceExport(
            actor: $request->user(),
            semesterId: $request->semester_id ?? Semester::where('status', 'aktif')->value('id'),
            prodiId: $request->prodi_id,
            kelas: $request->kelas,
            mataKuliahId: $request->mata_kuliah_id,
        );

        $filePath = $export->generate();

        return response()->download($filePath)->deleteFileAfterSend(true);
    }

    /**
     * Rekap seluruh jurusan (semua prodi)
     */
    public function byJurusan(Request $request): JsonResponse
    {
        $request->validate([
            'semester_id' => 'nullable|exists:semesters,id',
        ]);

        $semesterId = $request->semester_id ?? Semester::where('status', 'aktif')->value('id');

        $prodis = $this->authorization->scopeProdis(Prodi::query(), $request->user())->get();

        $totalHadir = 0;
        $totalAlpha = 0;
        $totalRecords = 0;
        $totalMahasiswa = 0;

        $perProdi = $prodis->map(function ($prodi) use ($request, $semesterId, &$totalHadir, &$totalAlpha, &$totalRecords, &$totalMahasiswa) {
            $mahasiswas = $this->authorization->scopeUsers(User::query(), $request->user())
                ->whereHas('roles', fn ($q) => $q->where('name', 'mahasiswa'))
                ->where('prodi_id', $prodi->id)
                ->where('status', 'aktif')
                ->pluck('id');

            $prodiHadir = 0;
            $prodiAlpha = 0;
            $prodiTotal = 0;

            foreach ($mahasiswas as $mhsId) {
                $attendances = $this->authorization->scopeAttendances(Attendance::query(), $request->user())
                    ->where('user_id', $mhsId)
                    ->whereHas('mataKuliah', fn ($q) => $q->where('semester_id', $semesterId))
                    ->get();

                $prodiHadir += $attendances->whereIn('status', ['hadir', 'hadir_terlambat'])->count();
                $prodiAlpha += $attendances->where('status', 'alpha')->count();
                $prodiTotal += $attendances->count();
            }

            $totalHadir += $prodiHadir;
            $totalAlpha += $prodiAlpha;
            $totalRecords += $prodiTotal;
            $totalMahasiswa += $mahasiswas->count();

            return [
                'prodi_id' => $prodi->id,
                'prodi_nama' => $prodi->nama,
                'prodi_kode' => $prodi->kode,
                'jumlah_mahasiswa' => $mahasiswas->count(),
                'total_hadir' => $prodiHadir,
                'total_alpha' => $prodiAlpha,
                'total_records' => $prodiTotal,
                'persentase_hadir' => $prodiTotal > 0 ? round(($prodiHadir / $prodiTotal) * 100, 1) : 0,
            ];
        });

        return $this->success([
            'semester_id' => $semesterId,
            'summary' => [
                'total_prodi' => $prodis->count(),
                'total_mahasiswa' => $totalMahasiswa,
                'total_hadir' => $totalHadir,
                'total_alpha' => $totalAlpha,
                'total_records' => $totalRecords,
                'persentase_hadir' => $totalRecords > 0 ? round(($totalHadir / $totalRecords) * 100, 1) : 0,
            ],
            'per_prodi' => $perProdi,
        ]);
    }
}
