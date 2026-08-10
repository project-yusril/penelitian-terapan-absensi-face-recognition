<?php

namespace App\Events;

use App\Models\Attendance;
use Illuminate\Foundation\Events\Dispatchable;

class AttendancePendingCreated
{
    use Dispatchable;

    public function __construct(public Attendance $attendance) {}
}
