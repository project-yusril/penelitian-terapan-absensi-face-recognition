<?php

namespace App\Models;

use App\Services\BiometricEncryptionService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FaceEmbedding extends Model
{
    protected $fillable = [
        'user_id',
        'embedding',
        'embedding_ciphertext',
        'embedding_key_id',
        'version',
        'status',
        'approved_by',
        'approved_at',
        'rejected_reason',
        'liveness_passed',
        'enrollment_device',
    ];

    protected $hidden = ['embedding', 'embedding_ciphertext', 'embedding_key_id'];

    protected function casts(): array
    {
        return [
            'liveness_passed' => 'boolean',
            'approved_at' => 'datetime',
            'version' => 'integer',
        ];
    }

    public function getEmbeddingAttribute(mixed $value): array
    {
        if (! $this->embedding_ciphertext || ! $this->embedding_key_id) {
            throw new \RuntimeException('Embedding belum terenkripsi');
        }

        return app(BiometricEncryptionService::class)->decrypt($this->embedding_ciphertext, $this->embedding_key_id);
    }

    public function setEmbeddingAttribute(array $value): void
    {
        $this->attributes['embedding_ciphertext'] = app(BiometricEncryptionService::class)->encrypt($value);
        $this->attributes['embedding_key_id'] = config('biometric.key_id');
        $this->attributes['embedding'] = json_encode([]);
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
