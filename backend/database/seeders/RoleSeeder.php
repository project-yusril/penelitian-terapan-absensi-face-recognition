<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['id' => 1, 'name' => 'super_admin', 'display_name' => 'Super Admin', 'description' => 'Administrator sistem dengan akses penuh'],
            ['id' => 2, 'name' => 'ketua_jurusan', 'display_name' => 'Ketua Jurusan', 'description' => 'Pimpinan jurusan'],
            ['id' => 3, 'name' => 'admin_jurusan', 'display_name' => 'Admin Jurusan', 'description' => 'Staff administrasi jurusan'],
            ['id' => 4, 'name' => 'kaprodi', 'display_name' => 'Ketua Program Studi', 'description' => 'Pimpinan program studi'],
            ['id' => 5, 'name' => 'admin_prodi', 'display_name' => 'Admin Program Studi', 'description' => 'Staff administrasi prodi'],
            ['id' => 6, 'name' => 'dosen', 'display_name' => 'Dosen', 'description' => 'Tenaga pengajar'],
            ['id' => 7, 'name' => 'mahasiswa', 'display_name' => 'Mahasiswa', 'description' => 'Peserta didik'],
            ['id' => 8, 'name' => 'orang_tua', 'display_name' => 'Orang Tua/Wali', 'description' => 'Orang tua atau wali mahasiswa'],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['id' => $role['id']], $role);
        }
    }
}
