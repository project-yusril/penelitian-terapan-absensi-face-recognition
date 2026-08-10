<?php

namespace App\Events;

use App\Models\LeaveRequest;
use Illuminate\Foundation\Events\Dispatchable;

class LeaveRequestProcessed
{
    use Dispatchable;

    public function __construct(
        public LeaveRequest $leaveRequest,
        public string $action, // 'approved' or 'rejected'
        public ?string $reason = null,
    ) {}
}
