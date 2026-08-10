<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('face_embeddings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->json('embedding');
            $table->integer('version')->default(1);
            $table->enum('status', ['pending', 'approved', 'rejected', 'inactive'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->boolean('liveness_passed')->default(false);
            $table->string('enrollment_device', 100)->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');

            $table->index('user_id', 'idx_embedding_user');
            $table->index('status', 'idx_embedding_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('face_embeddings');
    }
};
