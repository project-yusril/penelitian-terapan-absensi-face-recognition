<?php

namespace Database\Seeders;

use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\MataKuliah;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;

class JadwalSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('kode', '2025/2026-2')->first();

        $dosenYusril = User::where('email', 'dosen_yusril@gmail.com')->first();
        $dosenAdam = User::where('email', 'dosen_adam@gmail.com')->first();
        $dosenFitri = User::where('email', 'dosen_fitri@gmail.com')->first();

        // Get mata kuliah by kelas and dosen
        $mkYusrilB = MataKuliah::where('kode_mk', 'TI-401')->where('kelas', 'B')->where('dosen_id', $dosenYusril->id)->where('semester_id', $semester->id)->first();
        $mkAdamA = MataKuliah::where('kode_mk', 'TI-401')->where('kelas', 'A')->where('dosen_id', $dosenAdam->id)->where('semester_id', $semester->id)->first();
        $mkAdamC = MataKuliah::where('kode_mk', 'TI-401')->where('kelas', 'C')->where('dosen_id', $dosenAdam->id)->where('semester_id', $semester->id)->first();
        $mkFitriD = MataKuliah::where('kode_mk', 'TI-401')->where('kelas', 'D')->where('dosen_id', $dosenFitri->id)->where('semester_id', $semester->id)->first();
        $mkFitriE = MataKuliah::where('kode_mk', 'TI-401')->where('kelas', 'E')->where('dosen_id', $dosenFitri->id)->where('semester_id', $semester->id)->first();

        // Get geofences
        $lab1 = Geofence::where('nama', 'Lab Komputer 1')->first();
        $lab2 = Geofence::where('nama', 'Lab Komputer 2')->first();
        $lab3 = Geofence::where('nama', 'Lab Komputer 3')->first();
        $lab4 = Geofence::where('nama', 'Lab Komputer 4')->first();
        $lab5 = Geofence::where('nama', 'Lab Komputer 5')->first();

        $jadwals = [
            ['mata_kuliah_id' => $mkYusrilB->id, 'geofence_id' => $lab1->id, 'hari' => 'Senin', 'jam_mulai' => '08:00', 'jam_selesai' => '10:30', 'ruangan' => 'Lab Komputer 1'],
            ['mata_kuliah_id' => $mkAdamA->id, 'geofence_id' => $lab2->id, 'hari' => 'Senin', 'jam_mulai' => '08:00', 'jam_selesai' => '10:30', 'ruangan' => 'Lab Komputer 2'],
            ['mata_kuliah_id' => $mkAdamC->id, 'geofence_id' => $lab3->id, 'hari' => 'Selasa', 'jam_mulai' => '13:00', 'jam_selesai' => '15:30', 'ruangan' => 'Lab Komputer 3'],
            ['mata_kuliah_id' => $mkFitriD->id, 'geofence_id' => $lab4->id, 'hari' => 'Rabu', 'jam_mulai' => '08:00', 'jam_selesai' => '10:30', 'ruangan' => 'Lab Komputer 4'],
            ['mata_kuliah_id' => $mkFitriE->id, 'geofence_id' => $lab5->id, 'hari' => 'Rabu', 'jam_mulai' => '13:00', 'jam_selesai' => '15:30', 'ruangan' => 'Lab Komputer 5'],
        ];

        foreach ($jadwals as $data) {
            Jadwal::updateOrCreate(
                ['mata_kuliah_id' => $data['mata_kuliah_id'], 'hari' => $data['hari']],
                array_merge($data, ['status' => 'aktif'])
            );
        }
    }
}
