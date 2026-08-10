<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $password = fn () => Hash::make(Str::password(32));

        // ==================== Super Admin ====================
        $admin = User::updateOrCreate(
            ['email' => 'administrator@gmail.com'],

            [
                'nama' => 'Administrator',
                'password' => Hash::make('12345678'),
                'status' => 'aktif',
                'must_change_password' => true,
                'enrollment_status' => 'belum',
            ]
        );
        $admin->roles()->syncWithoutDetaching(Role::where('name', 'super_admin')->first()->id);

        // ==================== Ketua Jurusan ====================
        $kajur = User::updateOrCreate(
            ['email' => 'ketua_jurusan@gmail.com'],
            [
                'nama' => 'Dr. Bambang Sutrisno, M.T.',
                'password' => $password(),
                'status' => 'nonaktif',
                'must_change_password' => true,
                'enrollment_status' => 'belum',
            ]
        );
        $kajur->roles()->syncWithoutDetaching(Role::where('name', 'ketua_jurusan')->first()->id);

        // ==================== Admin Jurusan ====================
        $adminJurusan = User::updateOrCreate(
            ['email' => 'admin_jurusan@gmail.com'],
            [
                'nama' => 'Siti Rahayu, S.Kom.',
                'password' => $password(),
                'status' => 'nonaktif',
                'must_change_password' => true,
                'enrollment_status' => 'belum',
            ]
        );
        $adminJurusan->roles()->syncWithoutDetaching(Role::where('name', 'admin_jurusan')->first()->id);

        // ==================== Kaprodi (3) ====================
        $kaprodis = [
            ['nama' => 'Dr. Hendra Wijaya, M.T.', 'email' => 'kaprodi_elektro@gmail.com', 'prodi_id' => 3],
            ['nama' => 'Dr. Andi Prasetyo, M.Kom.', 'email' => 'kaprodi_informatika@gmail.com', 'prodi_id' => 2],
            ['nama' => 'Dr. Budi Santoso, M.T.', 'email' => 'kaprodi_listrik@gmail.com', 'prodi_id' => 1],
        ];

        $kaprodiRole = Role::where('name', 'kaprodi')->first();
        foreach ($kaprodis as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'nama' => $data['nama'],
                    'prodi_id' => $data['prodi_id'],
                    'password' => $password(),
                    'status' => 'nonaktif',
                    'must_change_password' => true,
                    'enrollment_status' => 'belum',
                ]
            );
            $user->roles()->syncWithoutDetaching($kaprodiRole->id);
        }

        // ==================== Admin Prodi (3) ====================
        $adminProdis = [
            ['nama' => 'Rina Wati, S.T.', 'email' => 'admin_prodi_elektro@gmail.com', 'prodi_id' => 3],
            ['nama' => 'Dewi Lestari, S.Kom.', 'email' => 'admin_prodi_informatika@gmail.com', 'prodi_id' => 2],
            ['nama' => 'Agus Setiawan, S.T.', 'email' => 'admin_prodi_listrik@gmail.com', 'prodi_id' => 1],
        ];

        $adminProdiRole = Role::where('name', 'admin_prodi')->first();
        foreach ($adminProdis as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'nama' => $data['nama'],
                    'prodi_id' => $data['prodi_id'],
                    'password' => $password(),
                    'status' => 'nonaktif',
                    'must_change_password' => true,
                    'enrollment_status' => 'belum',
                ]
            );
            $user->roles()->syncWithoutDetaching($adminProdiRole->id);
        }

        // ==================== Dosen TI (5) ====================
        $dosenTI = [
            ['nama' => 'Yusril Eka Mahendra, M.Kom.', 'email' => 'dosen_yusril@gmail.com', 'nidn' => '1234567890', 'prodi_id' => 2, 'pendidikan_terakhir' => 'S2 Teknik Informatika', 'bidang_keahlian' => 'Mobile Development, AI'],
            ['nama' => 'Adam Kurniawan, M.Kom.', 'email' => 'dosen_adam@gmail.com', 'nidn' => '1234567891', 'prodi_id' => 2, 'pendidikan_terakhir' => 'S2 Teknik Informatika', 'bidang_keahlian' => 'Web Development, Cloud Computing'],
            ['nama' => 'Fitri Handayani, M.Kom.', 'email' => 'dosen_fitri@gmail.com', 'nidn' => '1234567892', 'prodi_id' => 2, 'pendidikan_terakhir' => 'S2 Teknik Informatika', 'bidang_keahlian' => 'Data Science, Machine Learning'],
            ['nama' => 'Rudi Hartono, M.T.', 'email' => 'dosen_rudi@gmail.com', 'nidn' => '1234567893', 'prodi_id' => 2, 'pendidikan_terakhir' => 'S2 Teknik Elektro', 'bidang_keahlian' => 'IoT, Embedded Systems'],
            ['nama' => 'Sari Indah, M.Kom.', 'email' => 'dosen_sari@gmail.com', 'nidn' => '1234567894', 'prodi_id' => 2, 'pendidikan_terakhir' => 'S2 Teknik Informatika', 'bidang_keahlian' => 'Software Engineering, UI/UX'],
        ];

        // ==================== Dosen TE (2) ====================
        $dosenTE = [
            ['nama' => 'Wahyu Pratama, M.T.', 'email' => 'dosen_wahyu@gmail.com', 'nidn' => '2234567890', 'prodi_id' => 3, 'pendidikan_terakhir' => 'S2 Teknik Elektro', 'bidang_keahlian' => 'Power Electronics'],
            ['nama' => 'Dian Permata, M.T.', 'email' => 'dosen_dian@gmail.com', 'nidn' => '2234567891', 'prodi_id' => 3, 'pendidikan_terakhir' => 'S2 Teknik Elektro', 'bidang_keahlian' => 'Control Systems'],
        ];

        // ==================== Dosen TL (2) ====================
        $dosenTL = [
            ['nama' => 'Joko Susilo, M.T.', 'email' => 'dosen_joko@gmail.com', 'nidn' => '3234567890', 'prodi_id' => 1, 'pendidikan_terakhir' => 'S2 Teknik Elektro', 'bidang_keahlian' => 'Instalasi Listrik'],
            ['nama' => 'Mega Putri, M.T.', 'email' => 'dosen_mega@gmail.com', 'nidn' => '3234567891', 'prodi_id' => 1, 'pendidikan_terakhir' => 'S2 Teknik Elektro', 'bidang_keahlian' => 'Energi Terbarukan'],
        ];

        $dosenRole = Role::where('name', 'dosen')->first();
        $allDosen = array_merge($dosenTI, $dosenTE, $dosenTL);

        foreach ($allDosen as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'nama' => $data['nama'],
                    'nidn' => $data['nidn'],
                    'prodi_id' => $data['prodi_id'],
                    'pendidikan_terakhir' => $data['pendidikan_terakhir'],
                    'bidang_keahlian' => $data['bidang_keahlian'],
                    'password' => $password(),
                    'status' => 'nonaktif',
                    'must_change_password' => true,
                    'enrollment_status' => 'belum',
                ]
            );
            $user->roles()->syncWithoutDetaching($dosenRole->id);
        }

        // ==================== Mahasiswa TI (15) ====================
        $mahasiswas = [
            ['nama' => 'Ahmad Fauzi', 'email' => 'mahasiswa_ahmad@gmail.com', 'nim' => '2024001001', 'prodi_id' => 2, 'kelas' => 'B', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Budi Prasetyo', 'email' => 'mahasiswa_budi@gmail.com', 'nim' => '2024001002', 'prodi_id' => 2, 'kelas' => 'B', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Citra Dewi', 'email' => 'mahasiswa_citra@gmail.com', 'nim' => '2024001003', 'prodi_id' => 2, 'kelas' => 'B', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Dani Saputra', 'email' => 'mahasiswa_dani@gmail.com', 'nim' => '2024001004', 'prodi_id' => 2, 'kelas' => 'A', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Eka Putri', 'email' => 'mahasiswa_eka@gmail.com', 'nim' => '2024001005', 'prodi_id' => 2, 'kelas' => 'A', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Fajar Ramadhan', 'email' => 'mahasiswa_fajar@gmail.com', 'nim' => '2024001006', 'prodi_id' => 2, 'kelas' => 'A', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Gita Lestari', 'email' => 'mahasiswa_gita@gmail.com', 'nim' => '2024001007', 'prodi_id' => 2, 'kelas' => 'C', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Hadi Wijaya', 'email' => 'mahasiswa_hadi@gmail.com', 'nim' => '2024001008', 'prodi_id' => 2, 'kelas' => 'C', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Indra Kusuma', 'email' => 'mahasiswa_indra@gmail.com', 'nim' => '2024001009', 'prodi_id' => 2, 'kelas' => 'C', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Jihan Aulia', 'email' => 'mahasiswa_jihan@gmail.com', 'nim' => '2024001010', 'prodi_id' => 2, 'kelas' => 'D', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Kiki Amelia', 'email' => 'mahasiswa_kiki@gmail.com', 'nim' => '2024001011', 'prodi_id' => 2, 'kelas' => 'D', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Lukman Hakim', 'email' => 'mahasiswa_lukman@gmail.com', 'nim' => '2024001012', 'prodi_id' => 2, 'kelas' => 'D', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Mira Susanti', 'email' => 'mahasiswa_mira@gmail.com', 'nim' => '2024001013', 'prodi_id' => 2, 'kelas' => 'E', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Nanda Pratama', 'email' => 'mahasiswa_nanda@gmail.com', 'nim' => '2024001014', 'prodi_id' => 2, 'kelas' => 'E', 'angkatan' => 2024, 'semester' => 4],
            ['nama' => 'Oki Firmansyah', 'email' => 'mahasiswa_oki@gmail.com', 'nim' => '2024001015', 'prodi_id' => 2, 'kelas' => 'E', 'angkatan' => 2024, 'semester' => 4],
        ];

        $mahasiswaRole = Role::where('name', 'mahasiswa')->first();

        foreach ($mahasiswas as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'nama' => $data['nama'],
                    'nim' => $data['nim'],
                    'prodi_id' => $data['prodi_id'],
                    'kelas' => $data['kelas'],
                    'angkatan' => $data['angkatan'],
                    'semester' => $data['semester'],
                    'password' => $password(),
                    'status' => 'nonaktif',
                    'must_change_password' => true,
                    'enrollment_status' => 'belum',
                ]
            );
            $user->roles()->syncWithoutDetaching($mahasiswaRole->id);

        }

        // ==================== Orang Tua (3) ====================
        $orangTuaRole = Role::where('name', 'orang_tua')->first();

        $orangTuas = [
            ['nama' => 'Pak Fauzi', 'email' => 'orangtua_fauzi@gmail.com', 'student_nim' => '2024001001', 'hubungan' => 'ayah'],
            ['nama' => 'Bu Prasetyo', 'email' => 'orangtua_prasetyo@gmail.com', 'student_nim' => '2024001002', 'hubungan' => 'ibu'],
            ['nama' => 'Pak Saputra', 'email' => 'orangtua_saputra@gmail.com', 'student_nim' => '2024001004', 'hubungan' => 'ayah'],
        ];

        foreach ($orangTuas as $data) {
            $user = User::updateOrCreate(
                ['email' => $data['email']],
                [
                    'nama' => $data['nama'],
                    'password' => $password(),
                    'status' => 'nonaktif',
                    'must_change_password' => true,
                    'enrollment_status' => 'belum',
                ]
            );
            $user->roles()->syncWithoutDetaching($orangTuaRole->id);

            // Link parent to student
            $student = User::where('nim', $data['student_nim'])->first();
            if ($student) {
                DB::table('parent_student')->updateOrInsert(
                    ['parent_id' => $user->id, 'student_id' => $student->id],
                    ['hubungan' => $data['hubungan']]
                );
            }
        }
    }
}
