<?php

namespace Database\Seeders;

use App\Models\Semester;
use App\Models\TahunAjaran;
use Illuminate\Database\Seeder;

class SemesterSeeder extends Seeder
{
    public function run(): void
    {
        $ta = TahunAjaran::where('kode', '2025/2026')->first();

        Semester::updateOrCreate(
            ['tahun_ajaran_id' => $ta->id, 'kode' => '2025/2026-1'],
            [
                'nama' => 'Ganjil',
                'tanggal_mulai' => '2025-07-01',
                'tanggal_selesai' => '2025-12-31',
                'status' => 'nonaktif',
            ]
        );

        // Semester aktif ikut dipanjangkan sampai 2028: percuma memperpanjang
        // tahun ajaran saja, karena assertEligible memeriksa KEDUANYA dan
        // menolak begitu salah satu rentangnya terlewat.
        Semester::updateOrCreate(
            ['tahun_ajaran_id' => $ta->id, 'kode' => '2025/2026-2'],
            [
                'nama' => 'Genap',
                'tanggal_mulai' => '2026-01-01',
                'tanggal_selesai' => '2028-12-31',
                'status' => 'aktif',
            ]
        );
    }
}
