<?php

namespace Database\Seeders;

use App\Models\Prodi;
use App\Models\ProdiSetting;
use Illuminate\Database\Seeder;

class ProdiSettingSeeder extends Seeder
{
    public function run(): void
    {
        $prodis = Prodi::all();

        foreach ($prodis as $prodi) {
            ProdiSetting::updateOrCreate(
                ['prodi_id' => $prodi->id],
                [
                    'toleransi_masuk_menit' => 15,
                    'batas_terlambat_persen' => 50,
                    'toleransi_pulang_menit' => 15,
                    'sp1_jam_mulai' => 16,
                    'sp1_jam_akhir' => 31,
                    'sp2_jam_mulai' => 32,
                    'sp2_jam_akhir' => 37,
                    'sp3_jam_mulai' => 38,
                    'sp3_jam_akhir' => 45,
                    'do_jam_mulai' => 46,
                    'face_threshold' => 1.000,
                    'liveness_challenge_count' => 1,
                    'liveness_timeout_seconds' => 10,
                    'max_failed_attempts' => 5,
                    'default_radius_meter' => 50,
                    'gps_accuracy_minimum' => 20,
                    'gps_max_age_seconds' => 10,
                    'allow_offline_attendance' => true,
                    'offline_sync_timeout_menit' => 30,
                    'sp_warning_percentage' => 80,
                ]
            );
        }
    }
}
