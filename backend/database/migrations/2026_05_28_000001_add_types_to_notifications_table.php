<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM(
                'sp_warning', 'sp_issued', 'approval_needed', 'approval_result',
                'enrollment_result', 'reminder', 'system', 'attendance_reminder',
                'leave_request_result'
            ) NOT NULL");
        }
    }

    public function down(): void
    {
        $driver = DB::getDriverName();
        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE notifications MODIFY COLUMN type ENUM(
                'sp_warning', 'sp_issued', 'approval_needed', 'approval_result',
                'enrollment_result', 'reminder', 'system'
            ) NOT NULL");
        }
    }
};
