<?php

namespace App\Exports;

use App\Models\AlphaAccumulation;
use App\Models\Attendance;
use App\Models\MataKuliah;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class AttendanceExport
{
    protected ?int $semesterId;

    protected ?int $prodiId;

    protected ?string $kelas;

    protected ?int $mataKuliahId;

    /** M-16: filter mahasiswa agar export tab "Per Mahasiswa" sama dengan layar. */
    protected ?int $userId;

    protected User $actor;

    public function __construct(
        User $actor,
        ?int $semesterId = null,
        ?int $prodiId = null,
        ?string $kelas = null,
        ?int $mataKuliahId = null,
        ?int $userId = null,
    ) {
        $this->actor = $actor;
        $this->semesterId = $semesterId;
        $this->prodiId = $prodiId;
        $this->kelas = $kelas;
        $this->mataKuliahId = $mataKuliahId;
        $this->userId = $userId;
    }

    /**
     * Generate Excel file and return file path
     */
    public function generate(): string
    {
        $filePath = storage_path('app/exports/rekap_kehadiran_'.now()->format('Ymd_His').'_'.bin2hex(random_bytes(16)).'.xlsx');

        // Ensure directory exists
        if (! is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        $options = new Options;
        $writer = new Writer($options);
        $writer->openToFile($filePath);

        // Sheet 1: Summary
        $this->writeSummarySheet($writer);

        // Sheet 2: Detail per pertemuan
        $writer->addNewSheetAndMakeItCurrent();
        $this->writeDetailSheet($writer);

        // Sheet 3: Rincian alpha per MK
        $writer->addNewSheetAndMakeItCurrent();
        $this->writeAlphaSheet($writer);

        $writer->close();

        return $filePath;
    }

    protected function writeSummarySheet(Writer $writer): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Summary');

        // Header
        $headerStyle = (new Style)->setFontBold();

        $writer->addRow(Row::fromValues([
            'No', 'NIM', 'Nama', 'Kelas', 'Total Pertemuan',
            'Hadir', 'Terlambat', 'Alpha', 'Izin/Sakit',
            'Persentase Hadir (%)', 'Total Alpha (Menit)', 'Status SP',
        ], $headerStyle));

        // Data
        $mahasiswas = $this->getMahasiswas();
        // M-17: hitung statistik dan akumulasi alpha sekali untuk semua
        // mahasiswa, bukan query per mahasiswa di dalam loop.
        $statsByUser = $this->getStatsForUsers($mahasiswas->pluck('id')->all());
        $alphaByUser = $this->getAlphaAccumulations($mahasiswas->pluck('id')->all());
        $no = 1;

        foreach ($mahasiswas as $mhs) {
            $stats = $statsByUser[$mhs->id] ?? $this->emptyStats();
            $alpha = $alphaByUser[$mhs->id] ?? null;

            $writer->addRow(Row::fromValues([
                $no++,
                $mhs->nim,
                $mhs->nama,
                $mhs->kelas,
                $stats['total'],
                $stats['hadir'],
                $stats['terlambat'],
                $stats['alpha'],
                $stats['izin_sakit'],
                $stats['persentase'],
                $alpha->total_alpha_menit ?? 0,
                $alpha->sp_status ?? 'aman',
            ]));
        }
    }

    protected function writeDetailSheet(Writer $writer): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Detail Pertemuan');

        $headerStyle = (new Style)->setFontBold();

        $writer->addRow(Row::fromValues([
            'No', 'NIM', 'Nama', 'Mata Kuliah', 'Tanggal',
            'Check-in', 'Check-out', 'Status', 'Keterangan',
        ], $headerStyle));

        $query = $this->authorization()->scopeAttendances(
            Attendance::with(['user:id,nama,nim', 'mataKuliah:id,kode_mk,nama']),
            $this->actor,
        )
            ->when($this->semesterId, fn ($q) => $q->whereHas('mataKuliah', fn ($q2) => $q2->where('semester_id', $this->semesterId)))
            ->when($this->mataKuliahId, fn ($q) => $q->where('mata_kuliah_id', $this->mataKuliahId))
            ->when($this->kelas, fn ($q) => $q->whereHas('user', fn ($q2) => $q2->where('kelas', $this->kelas)))
            ->when($this->prodiId, fn ($q) => $q->whereHas('user', fn ($q2) => $q2->where('prodi_id', $this->prodiId)))
            ->when($this->userId, fn ($q) => $q->where('user_id', $this->userId))
            ->orderBy('tanggal')
            ->orderBy('user_id');

        $no = 1;
        $query->chunk(500, function ($attendances) use ($writer, &$no) {
            foreach ($attendances as $att) {
                $writer->addRow(Row::fromValues([
                    $no++,
                    $att->user->nim ?? '-',
                    $att->user->nama ?? '-',
                    $att->mataKuliah ? "{$att->mataKuliah->kode_mk} - {$att->mataKuliah->nama}" : '-',
                    $att->tanggal?->format('Y-m-d'),
                    $att->checkin_time?->format('H:i:s'),
                    $att->checkout_time?->format('H:i:s'),
                    $att->status,
                    $att->catatan ?? '',
                ]));
            }
        });
    }

    protected function writeAlphaSheet(Writer $writer): void
    {
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('Rincian Alpha per MK');

        $headerStyle = (new Style)->setFontBold();

        $writer->addRow(Row::fromValues([
            'No', 'NIM', 'Nama', 'Mata Kuliah', 'Total Alpha',
            'Alpha (Menit)', 'Persentase Alpha (%)',
        ], $headerStyle));

        $mahasiswas = $this->getMahasiswas();
        $mataKuliahs = $this->authorization()->scopeMataKuliahs(MataKuliah::query(), $this->actor)
            ->when($this->semesterId, fn ($q) => $q->where('semester_id', $this->semesterId))
            ->when($this->mataKuliahId, fn ($q) => $q->where('id', $this->mataKuliahId))
            ->when($this->prodiId, fn ($q) => $q->where('prodi_id', $this->prodiId))
            ->get();

        // M-17: satu query agregat menggantikan dua query per kombinasi
        // mahasiswa x mata kuliah.
        $counts = Attendance::whereIn('user_id', $mahasiswas->pluck('id'))
            ->whereIn('mata_kuliah_id', $mataKuliahs->pluck('id'))
            ->groupBy('user_id', 'mata_kuliah_id')
            ->selectRaw('user_id, mata_kuliah_id')
            ->selectRaw('COUNT(*) as total_pertemuan')
            ->selectRaw("SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha_count")
            ->get()
            ->keyBy(fn ($row) => $row->user_id.'-'.$row->mata_kuliah_id);

        $no = 1;
        foreach ($mahasiswas as $mhs) {
            foreach ($mataKuliahs as $mk) {
                $row = $counts->get($mhs->id.'-'.$mk->id);
                $alphaCount = (int) ($row->alpha_count ?? 0);

                if ($alphaCount === 0) {
                    continue;
                }

                $totalPertemuan = (int) ($row->total_pertemuan ?? 0);

                $alphaMenit = $alphaCount * $mk->sks * 50; // 1 SKS = 50 menit

                $writer->addRow(Row::fromValues([
                    $no++,
                    $mhs->nim,
                    $mhs->nama,
                    "{$mk->kode_mk} - {$mk->nama}",
                    $alphaCount,
                    $alphaMenit,
                    $totalPertemuan > 0 ? round(($alphaCount / $totalPertemuan) * 100, 1) : 0,
                ]));
            }
        }
    }

    protected function getMahasiswas(): Collection
    {
        return $this->authorization()->scopeUsers(User::query(), $this->actor)
            ->whereHas('roles', fn ($q) => $q->where('roles.name', 'mahasiswa'))
            ->when($this->prodiId, fn ($q) => $q->where('prodi_id', $this->prodiId))
            ->when($this->kelas, fn ($q) => $q->where('kelas', $this->kelas))
            ->when($this->userId, fn ($q) => $q->where('id', $this->userId))
            ->where('status', 'aktif')
            ->orderBy('nim')
            ->get();
    }

    /**
     * M-17: satu query agregat untuk seluruh mahasiswa, menggantikan
     * lima query per mahasiswa di dalam loop.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array<string, int|float>>
     */
    protected function getStatsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = $this->authorization()->scopeAttendances(Attendance::query(), $this->actor)
            ->whereIn('user_id', $userIds)
            ->when($this->semesterId, fn ($q) => $q->whereHas('mataKuliah', fn ($q2) => $q2->where('semester_id', $this->semesterId)))
            ->when($this->mataKuliahId, fn ($q) => $q->where('mata_kuliah_id', $this->mataKuliahId))
            ->groupBy('user_id')
            ->selectRaw('user_id')
            ->selectRaw('COUNT(*) as total')
            ->selectRaw("SUM(CASE WHEN status = 'hadir' THEN 1 ELSE 0 END) as hadir")
            ->selectRaw("SUM(CASE WHEN status = 'hadir_terlambat' THEN 1 ELSE 0 END) as terlambat")
            ->selectRaw("SUM(CASE WHEN status = 'alpha' THEN 1 ELSE 0 END) as alpha")
            ->selectRaw("SUM(CASE WHEN status IN ('izin','sakit') THEN 1 ELSE 0 END) as izin_sakit")
            ->get();

        $stats = [];
        foreach ($rows as $row) {
            $total = (int) $row->total;
            $hadir = (int) $row->hadir;
            $terlambat = (int) $row->terlambat;

            $stats[(int) $row->user_id] = [
                'total' => $total,
                'hadir' => $hadir,
                'terlambat' => $terlambat,
                'alpha' => (int) $row->alpha,
                'izin_sakit' => (int) $row->izin_sakit,
                'persentase' => $total > 0 ? round((($hadir + $terlambat) / $total) * 100, 1) : 0,
            ];
        }

        return $stats;
    }

    /**
     * @return array<string, int|float>
     */
    protected function emptyStats(): array
    {
        return [
            'total' => 0, 'hadir' => 0, 'terlambat' => 0,
            'alpha' => 0, 'izin_sakit' => 0, 'persentase' => 0,
        ];
    }

    /**
     * M-17: akumulasi alpha diambil sekali untuk semua mahasiswa.
     *
     * @param  array<int, int>  $userIds
     * @return array<int, AlphaAccumulation>
     */
    protected function getAlphaAccumulations(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return AlphaAccumulation::whereIn('user_id', $userIds)
            ->when($this->semesterId, fn ($q) => $q->where('semester_id', $this->semesterId))
            ->get()
            ->keyBy('user_id')
            ->all();
    }

    private function authorization(): AuthorizationService
    {
        return app(AuthorizationService::class);
    }
}
