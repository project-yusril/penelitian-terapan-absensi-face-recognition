<?php

namespace Tests;

use App\Models\Prodi;
use App\Models\Role;

trait SeedsEssentialData
{
    protected function seedEssentialData(): void
    {
        // Seed roles
        $roles = ['super_admin', 'admin_jurusan', 'admin_prodi', 'kaprodi', 'ketua_jurusan', 'dosen', 'mahasiswa', 'orang_tua'];
        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role], ['display_name' => ucfirst(str_replace('_', ' ', $role))]);
        }

        // Seed prodis
        Prodi::firstOrCreate(['kode' => 'TI'], ['nama' => 'Teknik Informatika', 'jenjang' => 'S1', 'status' => 'aktif']);
        Prodi::firstOrCreate(['kode' => 'TE'], ['nama' => 'Teknik Elektro', 'jenjang' => 'S1', 'status' => 'aktif']);
        Prodi::firstOrCreate(['kode' => 'TL'], ['nama' => 'Teknik Listrik', 'jenjang' => 'D3', 'status' => 'aktif']);
    }
}
