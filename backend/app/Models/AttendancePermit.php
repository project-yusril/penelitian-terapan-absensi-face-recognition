<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AttendancePermit extends Model
{
    protected $fillable = [
        'token_hash', 'permit_token', 'user_id', 'jadwal_id', 'mata_kuliah_id', 'attendance_id',
        'occurrence_date', 'action', 'client_uuid', 'liveness_challenge', 'encrypted_challenge',
        'not_before', 'capture_expires_at', 'sync_expires_at', 'consumed_at',
    ];

    protected $hidden = ['token_hash', 'permit_token', 'encrypted_challenge'];

    protected function casts(): array
    {
        return [
            'occurrence_date' => 'date',
            'permit_token' => 'encrypted',
            'encrypted_challenge' => 'encrypted',
            'not_before' => 'datetime',
            'capture_expires_at' => 'datetime',
            'sync_expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function getLivenessChallengeAttribute(?string $value): ?string
    {
        return $this->encrypted_challenge ?? $value;
    }
}
