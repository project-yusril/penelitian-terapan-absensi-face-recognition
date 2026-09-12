<?php

namespace Database\Seeders;

use App\Models\Kelas;
use App\Models\MahasiswaKelas;
use App\Models\Prodi;
use App\Models\Role;
use App\Models\Semester;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Mengisi data mahasiswa semester 1 (angkatan 2026) prodi D3 Teknik
 * Informatika dari daftar resmi Politeknik Negeri Pontianak TA 2026/2027:
 * 135 mahasiswa dalam 5 kelas (1A-1E), masing-masing 27 orang.
 *
 * Untuk setiap mahasiswa dibuat:
 *  - akun user (email sintetis nim@gmail.com, role mahasiswa,
 *    status aktif, enrollment belum, password default "12345678")
 *    agar bisa langsung dipakai uji coba;
 *  - pivot `mahasiswa_kelas` pada semester aktif lewat MahasiswaEnrollmentSynchronizer,
 *    sehingga KRS (kelas -> jadwal) langsung konsisten.
 *
 * Snapshot `users.kelas` memakai label "1A" dst. — observer menuliskan pivot
 * dan menyetel `users.semester` mengikuti tingkat kelas (1).
 */
class MahasiswaSemester1Seeder extends Seeder
{
    public function run(): void
    {
        $data = require __DIR__.'/data/mahasiswa_semester1_2026_2027.php';

        $semester = Semester::where('status', 'aktif')->first();
        if (! $semester) {
            $this->command?->warn('Tidak ada semester aktif — seeder dilewati.');

            return;
        }

        $prodi = Prodi::where('kode', 'TI')->first();
        if (! $prodi) {
            $this->command?->warn('Prodi Teknik Informatika tidak ditemukan — seeder dilewati.');

            return;
        }

        $mahasiswaRole = Role::where('name', 'mahasiswa')->first();
        $password = Hash::make('12345678');

        foreach ($data as $hurufKelas => $mahasiswas) {
            $kelas = Kelas::updateOrCreate(
                [
                    'prodi_id' => $prodi->id,
                    'semester_id' => $semester->id,
                    'tingkat' => '1',
                    'nama' => $hurufKelas,
                ],
                ['status' => 'aktif']
            );

            foreach ($mahasiswas as $mahasiswa) {
                $user = User::withTrashed()->updateOrCreate(
                    ['nim' => $mahasiswa['nim']],
                    [
                        'nama' => $mahasiswa['nama'],
                        'email' => $mahasiswa['nim'].'@gmail.com',
                        'password' => $password,
                        'prodi_id' => $prodi->id,
                        'kelas' => '1'.$hurufKelas,
                        'angkatan' => 2026,
                        'semester' => 1,
                        'status' => 'aktif',
                        'must_change_password' => true,
                        'enrollment_status' => 'belum',
                    ]
                );
                if ($user->trashed()) {
                    $user->restore();
                }
                $user->roles()->syncWithoutDetaching([$mahasiswaRole->id]);
            }

            // Pivot mengikuti snapshot: sinkronkan semua anggota kelas ini.
            $nims = array_column($mahasiswas, 'nim');
            User::whereIn('nim', $nims)->get()->each(function (User $user) use ($kelas, $semester) {
                MahasiswaKelas::updateOrCreate(
                    ['user_id' => $user->id, 'semester_id' => $semester->id],
                    ['kelas_id' => $kelas->id]
                );
            });

            $this->command?->line('Kelas 1'.$hurufKelas.': '.count($mahasiswas).' mahasiswa.');
        }
    }
}
