<?php

namespace App\Events;

use App\Models\SpRecord;
use Illuminate\Foundation\Events\Dispatchable;

class SpDocumentFinalized
{
    use Dispatchable;

    public function __construct(public SpRecord $spRecord) {}
}
