<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationOutbox extends Model
{
    protected $table = 'notification_outbox';

    protected $fillable = [
        'idempotency_key', 'user_id', 'type', 'title', 'body', 'data', 'processed_at',
        'attempt_count', 'next_attempt_at', 'last_error', 'lock_token', 'locked_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'processed_at' => 'datetime',
            'attempt_count' => 'integer',
            'next_attempt_at' => 'datetime',
            'locked_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
