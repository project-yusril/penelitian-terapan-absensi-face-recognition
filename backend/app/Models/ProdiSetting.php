<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProdiSetting extends Model
{
    protected $fillable = [
        'prodi_id',
        'toleransi_masuk_menit',
        'batas_terlambat_persen',
        'toleransi_pulang_menit',
        'sp1_jam_mulai',
        'sp1_jam_akhir',
        'sp2_jam_mulai',
        'sp2_jam_akhir',
        'sp3_jam_mulai',
        'sp3_jam_akhir',
        'do_jam_mulai',
        'face_threshold',
        'liveness_challenge_count',
        'liveness_timeout_seconds',
        'max_failed_attempts',
        'default_radius_meter',
        'gps_accuracy_minimum',
        'gps_max_age_seconds',
        'allow_offline_attendance',
        'offline_sync_timeout_menit',
        'sp_warning_percentage',
    ];

    protected function casts(): array
    {
        return [
            'allow_offline_attendance' => 'boolean',
            'face_threshold' => 'decimal:3',
        ];
    }

    public function prodi(): BelongsTo
    {
        return $this->belongsTo(Prodi::class);
    }
}
