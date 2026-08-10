<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $seeders = [
            RoleSeeder::class,
            ProdiSeeder::class,
            TahunAjaranSeeder::class,
            SemesterSeeder::class,
            MataKuliahSeeder::class,
            GeofenceSeeder::class,
            JadwalSeeder::class,
            MahasiswaMataKuliahSeeder::class,
            ProdiSettingSeeder::class,
            SystemSettingSeeder::class,
        ];
        if (! app()->environment('production')) {
            array_splice($seeders, 2, 0, [UserSeeder::class]);
        }
        $this->call($seeders);
    }
}
