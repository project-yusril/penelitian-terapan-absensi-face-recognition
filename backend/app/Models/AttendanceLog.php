<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'attendance_id',
        'user_id',
        'action',
        'status_before',
        'status_after',
        'latitude',
        'longitude',
        'distance_to_geofence',
        'face_distance',
        'face_threshold',
        'liveness_challenge',
        'inference_time_ms',
        'device_model',
        'device_os',
        'app_version',
        'gps_accuracy',
        'is_test_mode',
        'test_type',
        'error_message',
        'keterangan',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_test_mode' => 'boolean',
            'metadata' => 'array',
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
        ];
    }

    public function attendance(): BelongsTo
    {
        return $this->belongsTo(Attendance::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
