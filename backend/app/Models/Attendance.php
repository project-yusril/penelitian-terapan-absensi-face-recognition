<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attendance extends Model
{
    protected $fillable = [
        'client_uuid',
        'checkout_client_uuid',
        'user_id',
        'jadwal_id',
        'mata_kuliah_id',

        'tanggal',
        'pertemuan_ke',
        'checkin_time',
        'checkin_latitude',
        'checkin_longitude',
        'checkin_distance',
        'checkin_face_distance',
        'checkin_liveness_passed',
        'checkin_device',
        'checkout_time',
        'checkout_latitude',
        'checkout_longitude',
        'checkout_distance',
        'checkout_face_distance',
        'checkout_liveness_passed',
        'checkout_device',
        'status',
        'alpha_menit',
        'durasi_efektif_menit',
        'is_auto_closed',
        'is_offline_synced',
        'is_overridden',
        'overridden_by',
        'override_reason',
        'override_at',
        'approved_by',
        'approved_at',
        'approval_status',
        'catatan',
    ];

    protected function casts(): array
    {
        return [
            'tanggal' => 'date',
            'checkin_time' => 'datetime',
            'checkout_time' => 'datetime',
            'checkin_liveness_passed' => 'boolean',
            'checkout_liveness_passed' => 'boolean',
            'is_auto_closed' => 'boolean',
            'is_offline_synced' => 'boolean',
            'is_overridden' => 'boolean',
            'override_at' => 'datetime',
            'approved_at' => 'datetime',
            'checkin_latitude' => 'decimal:8',
            'checkin_longitude' => 'decimal:8',
            'checkout_latitude' => 'decimal:8',
            'checkout_longitude' => 'decimal:8',
            'checkin_distance' => 'decimal:2',
            'checkout_distance' => 'decimal:2',
            'checkin_face_distance' => 'decimal:6',
            'checkout_face_distance' => 'decimal:6',
            'pertemuan_ke' => 'integer',
            'alpha_menit' => 'integer',
            'durasi_efektif_menit' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function jadwal(): BelongsTo
    {
        return $this->belongsTo(Jadwal::class);
    }

    public function mataKuliah(): BelongsTo
    {
        return $this->belongsTo(MataKuliah::class);
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(AttendanceLog::class);
    }
}
