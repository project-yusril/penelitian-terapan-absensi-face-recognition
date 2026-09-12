<?php

namespace Database\Seeders;

use App\Models\MataKuliah;
use App\Models\Semester;
use Illuminate\Database\Seeder;

class MataKuliahSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('kode', '2025/2026-2')->first();
        if (! $semester) {
            return;
        }

        // RENCANA 2: MK murni master kurikulum (tanpa kelas/dosen).
        $mataKuliahs = [
            ['kode_mk' => 'TI-401', 'nama' => 'Pemrograman Mobile', 'sks' => 3, 'total_pertemuan' => 16],
            ['kode_mk' => 'TI-402', 'nama' => 'Pemrograman Web', 'sks' => 2, 'total_pertemuan' => 16],
            ['kode_mk' => 'TI-301', 'nama' => 'Basis Data', 'sks' => 3, 'total_pertemuan' => 16],
        ];

        foreach ($mataKuliahs as $mkData) {
            MataKuliah::updateOrCreate(
                [
                    'kode_mk' => $mkData['kode_mk'],
                    'semester_id' => $semester->id,
                    'prodi_id' => 2,
                ],
                array_merge($mkData, [
                    'semester_id' => $semester->id,
                    'prodi_id' => 2,
                    'status' => 'aktif',
                ])
            );
        }
    }
}
