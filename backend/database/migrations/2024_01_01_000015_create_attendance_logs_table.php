<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('attendance_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->enum('action', [
                'checkin_attempt', 'checkin_success', 'checkin_failed',
                'checkout_attempt', 'checkout_success', 'checkout_failed',
                'liveness_passed', 'liveness_failed',
                'face_match', 'face_not_match',
                'geofence_valid', 'geofence_invalid',
                'mock_location_detected',
            ]);

            $table->decimal('latitude', 10, 8)->nullable();
            $table->decimal('longitude', 11, 8)->nullable();
            $table->decimal('distance_to_geofence', 8, 2)->nullable();
            $table->decimal('face_distance', 10, 6)->nullable();
            $table->decimal('face_threshold', 10, 6)->nullable();
            $table->string('liveness_challenge', 50)->nullable();
            $table->integer('inference_time_ms')->nullable();

            $table->string('device_model', 100)->nullable();
            $table->string('device_os', 50)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->decimal('gps_accuracy', 8, 2)->nullable();

            $table->boolean('is_test_mode')->default(false);
            $table->enum('test_type', ['genuine', 'impostor'])->nullable();

            $table->text('error_message')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('attendance_id')->references('id')->on('attendances')->onDelete('set null');
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index('user_id', 'idx_log_user');
            $table->index('action', 'idx_log_action');
            $table->index('created_at', 'idx_log_created');
            $table->index(['is_test_mode', 'test_type'], 'idx_log_test');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_logs');
    }
};
