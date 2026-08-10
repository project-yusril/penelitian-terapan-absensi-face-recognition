<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alpha_accumulations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('semester_id');
            $table->integer('total_alpha_menit')->default(0);
            $table->decimal('total_alpha_jam', 8, 2)->nullable();
            $table->enum('sp_status', ['aman', 'sp1', 'sp2', 'sp3', 'do'])->default('aman');
            $table->timestamp('last_calculated_at')->nullable();

            $table->boolean('notified_approaching_sp1')->default(false);
            $table->boolean('notified_sp1')->default(false);
            $table->boolean('notified_approaching_sp2')->default(false);
            $table->boolean('notified_sp2')->default(false);
            $table->boolean('notified_approaching_sp3')->default(false);
            $table->boolean('notified_sp3')->default(false);
            $table->boolean('notified_approaching_do')->default(false);
            $table->boolean('notified_do')->default(false);

            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('semester_id')->references('id')->on('semesters')->onDelete('cascade');

            $table->unique(['user_id', 'semester_id'], 'unique_user_semester');
            $table->index('sp_status', 'idx_alpha_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alpha_accumulations');
    }
};
