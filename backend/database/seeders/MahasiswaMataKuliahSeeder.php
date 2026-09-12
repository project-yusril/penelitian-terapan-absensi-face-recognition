<?php

namespace Database\Seeders;

use App\Models\Kelas;
use App\Models\MahasiswaKelas;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;

class MahasiswaMataKuliahSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('kode', '2025/2026-2')->first();
        if (! $semester) {
            return;
        }

        // RENCANA 2: KRS diturunkan dari mahasiswa_kelas → kelas → jadwal.
        // Seeder ini hanya memastikan pivot mahasiswa_kelas terisi dari snapshot.
        $kelasMapping = [
            'B' => ['2024001001', '2024001002', '2024001003'],
            'A' => ['2024001004', '2024001005', '2024001006'],
            'C' => ['2024001007', '2024001008', '2024001009'],
            'D' => ['2024001010', '2024001011', '2024001012'],
            'E' => ['2024001013', '2024001014', '2024001015'],
        ];

        foreach ($kelasMapping as $kelas => $nims) {
            $kelasMaster = Kelas::where('semester_id', $semester->id)->where('nama', $kelas)->first();
            if (! $kelasMaster) {
                continue;
            }

            foreach (User::whereIn('nim', $nims)->get() as $user) {
                MahasiswaKelas::updateOrCreate(
                    ['user_id' => $user->id, 'semester_id' => $semester->id],
                    ['kelas_id' => $kelasMaster->id]
                );
            }
        }
    }
}
