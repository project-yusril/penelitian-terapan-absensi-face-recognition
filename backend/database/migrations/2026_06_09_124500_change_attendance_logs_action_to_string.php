<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE attendance_logs MODIFY action VARCHAR(100) NOT NULL');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE attendance_logs MODIFY action ENUM('checkin_attempt','checkin_success','checkin_failed','checkout_attempt','checkout_success','checkout_failed','liveness_passed','liveness_failed','face_match','face_not_match','geofence_valid','geofence_invalid','mock_location_detected') NOT NULL");
        }
    }
};
