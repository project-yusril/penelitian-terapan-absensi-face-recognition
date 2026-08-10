<?php

namespace App\Events;

use App\Models\Attendance;
use Illuminate\Foundation\Events\Dispatchable;

class AttendanceApproved
{
    use Dispatchable;

    public function __construct(
        public Attendance $attendance,
        public string $action, // 'approved' or 'rejected'
        public ?string $reason = null,
    ) {}
}
