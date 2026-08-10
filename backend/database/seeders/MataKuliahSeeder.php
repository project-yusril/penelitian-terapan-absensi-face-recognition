<?php

namespace Database\Seeders;

use App\Models\MataKuliah;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;

class MataKuliahSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('kode', '2025/2026-2')->first();

        $dosenYusril = User::where('email', 'dosen_yusril@gmail.com')->first();
        $dosenAdam = User::where('email', 'dosen_adam@gmail.com')->first();
        $dosenFitri = User::where('email', 'dosen_fitri@gmail.com')->first();

        $mataKuliahs = [
            ['kode_mk' => 'TI-401', 'nama' => 'Pemrograman Mobile', 'sks' => 3, 'dosen_id' => $dosenYusril->id, 'kelas' => 'B', 'total_pertemuan' => 16],
            ['kode_mk' => 'TI-401', 'nama' => 'Pemrograman Mobile', 'sks' => 3, 'dosen_id' => $dosenAdam->id, 'kelas' => 'A', 'total_pertemuan' => 16],
            ['kode_mk' => 'TI-401', 'nama' => 'Pemrograman Mobile', 'sks' => 3, 'dosen_id' => $dosenAdam->id, 'kelas' => 'C', 'total_pertemuan' => 16],
            ['kode_mk' => 'TI-401', 'nama' => 'Pemrograman Mobile', 'sks' => 3, 'dosen_id' => $dosenFitri->id, 'kelas' => 'D', 'total_pertemuan' => 16],
            ['kode_mk' => 'TI-401', 'nama' => 'Pemrograman Mobile', 'sks' => 3, 'dosen_id' => $dosenFitri->id, 'kelas' => 'E', 'total_pertemuan' => 16],
        ];

        foreach ($mataKuliahs as $mkData) {
            MataKuliah::updateOrCreate(
                [
                    'kode_mk' => $mkData['kode_mk'],
                    'semester_id' => $semester->id,
                    'kelas' => $mkData['kelas'],
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
