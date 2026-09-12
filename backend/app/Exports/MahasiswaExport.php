<?php

namespace App\Exports;

use App\Models\User;
use Illuminate\Support\Collection;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

class MahasiswaExport
{
    protected ?int $prodiId;

    protected ?string $kelas;

    protected ?int $angkatan;

    public function __construct(?int $prodiId = null, ?string $kelas = null, ?int $angkatan = null)
    {
        $this->prodiId = $prodiId;
        $this->kelas = $kelas;
        $this->angkatan = $angkatan;
    }

    /**
     * Generate Excel file and return file path
     */
    public function generate(): string
    {
        $filePath = storage_path('app/exports/mahasiswa_'.now()->format('Ymd_His').'.xlsx');

        if (! is_dir(dirname($filePath))) {
            mkdir(dirname($filePath), 0755, true);
        }

        $options = new Options;
        $writer = new Writer($options);
        $writer->openToFile($filePath);

        $headerStyle = (new Style)->withFontBold(true);

        $writer->addRow(Row::fromValuesWithStyle([
            'No', 'NIM', 'Nama', 'Email', 'No HP', 'Prodi', 'Kelas',
            'Angkatan', 'Semester', 'Jenis Kelamin', 'Status', 'Enrollment Status',
        ], $headerStyle));

        $mahasiswas = $this->getMahasiswas();
        $no = 1;

        foreach ($mahasiswas as $mhs) {
            // RENCANA 2: label kelas dari pivot master bila tersedia.
            $kelasAktif = $mhs->mahasiswaKelas->first(fn ($mk) => $mk->semester?->status === 'aktif')?->kelas;
            $labelKelas = $kelasAktif ? $kelasAktif->tingkat.$kelasAktif->nama : $mhs->kelas;

            $writer->addRow(Row::fromValues([
                $no++,
                $mhs->nim,
                $mhs->nama,
                $mhs->email,
                $mhs->no_hp ?? '-',
                $mhs->prodi?->nama ?? '-',
                $labelKelas ?? '-',
                $mhs->angkatan ?? '-',
                $mhs->semester ?? '-',
                $mhs->jenis_kelamin ?? '-',
                $mhs->status,
                $mhs->enrollment_status,
            ]));
        }

        $writer->close();

        return $filePath;
    }

    protected function getMahasiswas(): Collection
    {
        return User::whereHas('roles', fn ($q) => $q->where('roles.name', 'mahasiswa'))
            ->when($this->prodiId, fn ($q) => $q->where('prodi_id', $this->prodiId))
            // RENCANA 2: filter kelas via pivot master (label "4B").
            ->when($this->kelas, fn ($q) => $q->whereHas('mahasiswaKelas.kelas', fn ($k) => $k
                ->whereRaw("CONCAT(tingkat, nama) = ?", [$this->kelas])))
            ->when($this->angkatan, fn ($q) => $q->where('angkatan', $this->angkatan))
            ->with(['prodi:id,kode,nama', 'mahasiswaKelas.semester:id,status', 'mahasiswaKelas.kelas:id,tingkat,nama'])
            ->orderBy('nim')
            ->get();
    }
}
