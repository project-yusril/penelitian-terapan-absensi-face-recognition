<?php

namespace App\Models;

use App\Services\BiometricEncryptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReEnrollmentRequest extends Model
{
    protected $hidden = ['foto_baru', 'new_embedding', 'new_embedding_ciphertext', 'new_embedding_key_id'];

    protected $fillable = [
        'user_id',
        'alasan',
        'keterangan',
        'foto_baru',
        'new_embedding',
        'new_embedding_ciphertext',
        'new_embedding_key_id',
        'status',
        'approved_by',
        'approved_at',
        'rejected_reason',
    ];

    protected function casts(): array
    {
        return [
            'approved_at' => 'datetime',
        ];
    }

    public function getNewEmbeddingAttribute(mixed $value): array
    {
        if (! $this->new_embedding_ciphertext || ! $this->new_embedding_key_id) {
            throw new \RuntimeException('Embedding re-enrollment belum terenkripsi');
        }

        return app(BiometricEncryptionService::class)
            ->decrypt($this->new_embedding_ciphertext, $this->new_embedding_key_id);
    }

    public function setNewEmbeddingAttribute(array $value): void
    {
        $this->attributes['new_embedding_ciphertext'] = app(BiometricEncryptionService::class)->encrypt($value);
        $this->attributes['new_embedding_key_id'] = config('biometric.key_id');
        $this->attributes['new_embedding'] = json_encode([]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
