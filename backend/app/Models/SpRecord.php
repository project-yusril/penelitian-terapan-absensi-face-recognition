<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SpRecord extends Model
{
    protected $fillable = [
        'user_id',
        'semester_id',
        'sp_level',
        'nomor_surat',
        'tanggal_terbit',
        'total_alpha_jam',
        'rincian_alpha',
        'status',
        'generated_by',
        'generated_at',
        'signed_kaprodi_by',
        'signed_kaprodi_at',
        'signed_kajur_by',
        'signed_kajur_at',
        'document_path',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'tanggal_terbit' => 'date',
            'rincian_alpha' => 'array',
            'generated_at' => 'datetime',
            'signed_kaprodi_at' => 'datetime',
            'signed_kajur_at' => 'datetime',
            'total_alpha_jam' => 'decimal:2',
        ];
    }

    public function getLevelAttribute(): string
    {
        return strtoupper($this->sp_level ?? '');
    }

    public function getTotalAlphaAttribute(): float
    {
        return (float) ($this->total_alpha_jam ?? 0);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function semester(): BelongsTo
    {
        return $this->belongsTo(Semester::class);
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }

    public function signedKaprodiBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_kaprodi_by');
    }

    public function signedKajurBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_kajur_by');
    }
}
