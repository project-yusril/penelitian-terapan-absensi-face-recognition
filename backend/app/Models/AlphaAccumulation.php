<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AlphaAccumulation extends Model
{
    protected $fillable = [
        'user_id',
        'semester_id',
        'total_alpha_menit',
        'total_alpha_jam',
        'sp_status',
        'last_calculated_at',
        'notified_approaching_sp1',
        'notified_sp1',
        'notified_approaching_sp2',
        'notified_sp2',
        'notified_approaching_sp3',
        'notified_sp3',
        'notified_approaching_do',
        'notified_do',
    ];

    protected function casts(): array
    {
        return [
            'last_calculated_at' => 'datetime',
            'notified_approaching_sp1' => 'boolean',
            'notified_sp1' => 'boolean',
            'notified_approaching_sp2' => 'boolean',
            'notified_sp2' => 'boolean',
            'notified_approaching_sp3' => 'boolean',
            'notified_sp3' => 'boolean',
            'notified_approaching_do' => 'boolean',
            'notified_do' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }
}
