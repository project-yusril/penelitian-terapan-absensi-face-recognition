<?php

namespace Database\Seeders;

use App\Models\Geofence;
use Illuminate\Database\Seeder;

class GeofenceSeeder extends Seeder
{
    public function run(): void
    {
        $geofences = [
            ['nama' => 'Lab Komputer 1', 'latitude' => -0.02340000, 'longitude' => 109.34560000, 'radius' => 50, 'gedung' => 'Gedung A', 'lantai' => '2', 'prodi_id' => 2],
            ['nama' => 'Lab Komputer 2', 'latitude' => -0.02350000, 'longitude' => 109.34600000, 'radius' => 50, 'gedung' => 'Gedung A', 'lantai' => '2', 'prodi_id' => 2],
            ['nama' => 'Lab Komputer 3', 'latitude' => -0.02400000, 'longitude' => 109.34700000, 'radius' => 50, 'gedung' => 'Gedung B', 'lantai' => '1', 'prodi_id' => 2],
            ['nama' => 'Lab Komputer 4', 'latitude' => -0.02450000, 'longitude' => 109.34800000, 'radius' => 50, 'gedung' => 'Gedung B', 'lantai' => '2', 'prodi_id' => 2],
            ['nama' => 'Lab Komputer 5', 'latitude' => -0.02500000, 'longitude' => 109.34900000, 'radius' => 50, 'gedung' => 'Gedung C', 'lantai' => '1', 'prodi_id' => 2],
            ['nama' => 'Ruang Teori 1', 'latitude' => -0.02360000, 'longitude' => 109.34580000, 'radius' => 50, 'gedung' => 'Gedung A', 'lantai' => '3', 'prodi_id' => 3],
            ['nama' => 'Ruang Teori 2', 'latitude' => -0.02370000, 'longitude' => 109.34620000, 'radius' => 50, 'gedung' => 'Gedung A', 'lantai' => '3', 'prodi_id' => 1],
        ];

        foreach ($geofences as $data) {
            Geofence::updateOrCreate(
                ['nama' => $data['nama']],
                array_merge($data, ['status' => 'aktif'])
            );
        }
    }
}
