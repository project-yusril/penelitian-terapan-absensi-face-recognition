<?php

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'test_mode', 'value' => 'false', 'type' => 'boolean', 'description' => 'Mode testing untuk development'],
            ['key' => 'app_name', 'value' => 'Sistem Absensi Mahasiswa', 'type' => 'string', 'description' => 'Nama aplikasi'],
            ['key' => 'institution_name', 'value' => 'Politeknik Negeri Pontianak', 'type' => 'string', 'description' => 'Nama institusi'],
            ['key' => 'jurusan_name', 'value' => 'Teknik Elektro', 'type' => 'string', 'description' => 'Nama jurusan'],
        ];

        foreach ($settings as $setting) {
            SystemSetting::updateOrCreate(['key' => $setting['key']], $setting);
        }
    }
}
