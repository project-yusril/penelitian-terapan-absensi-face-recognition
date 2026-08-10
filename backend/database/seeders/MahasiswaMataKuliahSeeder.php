<?php

namespace Database\Seeders;

use App\Models\MataKuliah;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;

class MahasiswaMataKuliahSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('kode', '2025/2026-2')->first();

        // Map kelas to NIM list
        $kelasMapping = [
            'B' => ['2024001001', '2024001002', '2024001003'],
            'A' => ['2024001004', '2024001005', '2024001006'],
            'C' => ['2024001007', '2024001008', '2024001009'],
            'D' => ['2024001010', '2024001011', '2024001012'],
            'E' => ['2024001013', '2024001014', '2024001015'],
        ];

        foreach ($kelasMapping as $kelas => $nims) {
            $mk = MataKuliah::where('kode_mk', 'TI-401')
                ->where('kelas', $kelas)
                ->where('semester_id', $semester->id)
                ->first();

            if (! $mk) {
                continue;
            }

            $mahasiswaIds = User::whereIn('nim', $nims)->pluck('id');
            $mk->mahasiswas()->syncWithoutDetaching($mahasiswaIds);
        }
    }
}
