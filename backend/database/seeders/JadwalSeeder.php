<?php

namespace Database\Seeders;

use App\Models\Geofence;
use App\Models\Jadwal;
use App\Models\Kelas;
use App\Models\MataKuliah;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;

class JadwalSeeder extends Seeder
{
    public function run(): void
    {
        $semester = Semester::where('kode', '2025/2026-2')->first();
        if (! $semester) {
            return;
        }

        $dosenYusril = User::where('email', 'dosen_yusril@gmail.com')->first();
        $dosenAdam = User::where('email', 'dosen_adam@gmail.com')->first();
        $dosenFitri = User::where('email', 'dosen_fitri@gmail.com')->first();

        // RENCANA 2: satu MK master per kode; kelas & dosen di-plot di jadwal.
        $mk401 = MataKuliah::where('kode_mk', 'TI-401')->where('semester_id', $semester->id)->first();
        $mk402 = MataKuliah::where('kode_mk', 'TI-402')->where('semester_id', $semester->id)->first();

        // Get geofences
        $lab1 = Geofence::where('nama', 'Lab Komputer 1')->first();
        $lab2 = Geofence::where('nama', 'Lab Komputer 2')->first();
        $lab3 = Geofence::where('nama', 'Lab Komputer 3')->first();
        $lab4 = Geofence::where('nama', 'Lab Komputer 4')->first();
        $lab5 = Geofence::where('nama', 'Lab Komputer 5')->first();

        $kelas = fn (string $nama) => Kelas::where('semester_id', $semester->id)->where('nama', $nama)->first();

        $jadwals = [];
        if ($mk401) {
            if ($kelasA = $kelas('A')) {
                $jadwals[] = ['mata_kuliah_id' => $mk401->id, 'kelas_id' => $kelasA->id, 'dosen_id' => $dosenAdam?->id, 'geofence_id' => $lab2->id, 'hari' => 'Senin', 'jam_mulai' => '08:00', 'jam_selesai' => '10:30', 'ruangan' => 'Lab Komputer 2'];
            }
            if ($kelasC = $kelas('C')) {
                $jadwals[] = ['mata_kuliah_id' => $mk401->id, 'kelas_id' => $kelasC->id, 'dosen_id' => $dosenAdam?->id, 'geofence_id' => $lab3->id, 'hari' => 'Selasa', 'jam_mulai' => '13:00', 'jam_selesai' => '15:30', 'ruangan' => 'Lab Komputer 3'];
            }
            if ($kelasD = $kelas('D')) {
                $jadwals[] = ['mata_kuliah_id' => $mk401->id, 'kelas_id' => $kelasD->id, 'dosen_id' => $dosenFitri?->id, 'geofence_id' => $lab4->id, 'hari' => 'Rabu', 'jam_mulai' => '08:00', 'jam_selesai' => '10:30', 'ruangan' => 'Lab Komputer 4'];
            }
            if ($kelasE = $kelas('E')) {
                $jadwals[] = ['mata_kuliah_id' => $mk401->id, 'kelas_id' => $kelasE->id, 'dosen_id' => $dosenFitri?->id, 'geofence_id' => $lab5->id, 'hari' => 'Rabu', 'jam_mulai' => '13:00', 'jam_selesai' => '15:30', 'ruangan' => 'Lab Komputer 5'];
            }
        }
        if ($mk402) {
            if ($kelasB = $kelas('B')) {
                $jadwals[] = ['mata_kuliah_id' => $mk402->id, 'kelas_id' => $kelasB->id, 'dosen_id' => $dosenYusril?->id, 'geofence_id' => $lab1->id, 'hari' => 'Senin', 'jam_mulai' => '08:00', 'jam_selesai' => '10:30', 'ruangan' => 'Lab Komputer 1'];
            }
        }

        foreach ($jadwals as $data) {
            Jadwal::updateOrCreate(
                ['mata_kuliah_id' => $data['mata_kuliah_id'], 'kelas_id' => $data['kelas_id']],
                array_merge($data, ['status' => 'aktif'])
            );
        }
    }
}
