<?php

namespace Database\Seeders;

use App\Models\TahunAjaran;
use Illuminate\Database\Seeder;

class TahunAjaranSeeder extends Seeder
{
    public function run(): void
    {
        // Rentang sengaja dipanjangkan sampai 2028.
        //
        // AttendancePermitService::assertEligible tidak hanya memeriksa
        // `status = aktif`, tetapi juga menuntut tanggal absensi berada di
        // dalam rentang tahun ajaran DAN semester. Dengan rentang default yang
        // pendek, seluruh fitur absensi berhenti bekerja begitu tanggalnya
        // lewat — gejalanya berupa 422 yang membingungkan, bukan pesan
        // "periode berakhir" yang jelas. Untuk data pengembangan, rentang
        // panjang menghindari jebakan itu.
        TahunAjaran::updateOrCreate(
            ['kode' => '2025/2026'],
            [
                'nama' => 'Tahun Ajaran 2025/2026',
                'tanggal_mulai' => '2025-07-01',
                'tanggal_selesai' => '2028-12-31',
                'status' => 'aktif',
            ]
        );
    }
}
