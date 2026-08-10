<?php

namespace Database\Seeders;

use App\Models\Prodi;
use Illuminate\Database\Seeder;

class ProdiSeeder extends Seeder
{
    public function run(): void
    {
        $prodis = [
            ['id' => 1, 'kode' => 'TL', 'nama' => 'Teknik Listrik', 'jenjang' => 'D3', 'jurusan' => 'Teknik Elektro'],
            ['id' => 2, 'kode' => 'TI', 'nama' => 'Teknik Informatika', 'jenjang' => 'D3', 'jurusan' => 'Teknik Elektro'],
            ['id' => 3, 'kode' => 'TE', 'nama' => 'Teknik Elektro', 'jenjang' => 'D3', 'jurusan' => 'Teknik Elektro'],
        ];

        foreach ($prodis as $prodiData) {
            Prodi::updateOrCreate(['id' => $prodiData['id']], $prodiData);
        }
    }
}
