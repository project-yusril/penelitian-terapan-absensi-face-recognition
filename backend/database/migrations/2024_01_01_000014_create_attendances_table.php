<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendances', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('jadwal_id');
            $table->unsignedBigInteger('mata_kuliah_id');
            $table->date('tanggal');
            $table->integer('pertemuan_ke')->nullable();

            // Check-in fields
            $table->timestamp('checkin_time')->nullable();
            $table->decimal('checkin_latitude', 10, 8)->nullable();
            $table->decimal('checkin_longitude', 11, 8)->nullable();
            $table->decimal('checkin_distance', 8, 2)->nullable();
            $table->decimal('checkin_face_distance', 10, 6)->nullable();
            $table->boolean('checkin_liveness_passed')->default(false);
            $table->string('checkin_device', 100)->nullable();

            // Check-out fields
            $table->timestamp('checkout_time')->nullable();
            $table->decimal('checkout_latitude', 10, 8)->nullable();
            $table->decimal('checkout_longitude', 11, 8)->nullable();
            $table->decimal('checkout_distance', 8, 2)->nullable();
            $table->decimal('checkout_face_distance', 10, 6)->nullable();
            $table->boolean('checkout_liveness_passed')->default(false);
            $table->string('checkout_device', 100)->nullable();

            // Status fields
            $table->enum('status', ['hadir', 'hadir_terlambat', 'pending', 'alpha', 'izin', 'sakit'])->default('alpha');
            $table->integer('alpha_menit')->default(0);
            $table->integer('durasi_efektif_menit')->default(0);

            // Override fields
            $table->boolean('is_auto_closed')->default(false);
            $table->boolean('is_offline_synced')->default(false);
            $table->boolean('is_overridden')->default(false);
            $table->unsignedBigInteger('overridden_by')->nullable();
            $table->text('override_reason')->nullable();

            // Approval fields
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->enum('approval_status', ['pending', 'approved', 'rejected'])->nullable();

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('jadwal_id')->references('id')->on('jadwals')->onDelete('cascade');
            $table->foreign('mata_kuliah_id')->references('id')->on('mata_kuliahs')->onDelete('cascade');
            $table->foreign('overridden_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');

            $table->unique(['user_id', 'jadwal_id', 'tanggal'], 'unique_attendance');
            $table->index('user_id', 'idx_att_user');
            $table->index('jadwal_id', 'idx_att_jadwal');
            $table->index('tanggal', 'idx_att_tanggal');
            $table->index('status', 'idx_att_status');
            $table->index('mata_kuliah_id', 'idx_att_mk');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendances');
    }
};
