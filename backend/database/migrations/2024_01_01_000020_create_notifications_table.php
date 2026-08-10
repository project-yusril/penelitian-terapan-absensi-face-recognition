<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title', 200);
            $table->text('body');
            $table->enum('type', [
                'sp_warning', 'sp_issued', 'approval_needed', 'approval_result',
                'enrollment_result', 'reminder', 'system',
            ]);
            $table->json('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->timestamp('read_at')->nullable();
            $table->enum('sent_via', ['push', 'in_app', 'both'])->default('both');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');

            $table->index('user_id', 'idx_notif_user');
            $table->index('is_read', 'idx_notif_read');
            $table->index('type', 'idx_notif_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
