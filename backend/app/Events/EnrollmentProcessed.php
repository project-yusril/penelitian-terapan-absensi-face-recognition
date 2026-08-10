<?php

namespace App\Events;

use App\Models\FaceEmbedding;
use Illuminate\Foundation\Events\Dispatchable;

class EnrollmentProcessed
{
    use Dispatchable;

    public function __construct(
        public FaceEmbedding $embedding,
        public string $action, // 'approved' or 'rejected'
        public ?string $reason = null,
    ) {}
}
