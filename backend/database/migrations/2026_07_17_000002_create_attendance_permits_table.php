<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_permits', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('jadwal_id')->constrained('jadwals')->cascadeOnDelete();
            $table->foreignId('mata_kuliah_id')->constrained('mata_kuliahs')->cascadeOnDelete();
            $table->foreignId('attendance_id')->nullable()->constrained('attendances')->cascadeOnDelete();
            $table->date('occurrence_date');
            $table->enum('action', ['check_in', 'check_out']);
            $table->uuid('client_uuid');
            $table->string('liveness_challenge', 50);
            $table->timestamp('not_before');
            $table->timestamp('capture_expires_at');
            $table->timestamp('sync_expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'client_uuid', 'action']);
            $table->index(['user_id', 'occurrence_date', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_permits');
    }
};
