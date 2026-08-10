<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_enrollment_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->enum('alasan', ['potong_rambut', 'pakai_jilbab', 'lepas_jilbab', 'perubahan_lain']);
            $table->text('keterangan')->nullable();
            $table->string('foto_baru', 255);
            $table->json('new_embedding');
            $table->text('new_embedding_ciphertext');
            $table->string('new_embedding_key_id', 100);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->unsignedBigInteger('approved_by')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejected_reason')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('approved_by')->references('id')->on('users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_enrollment_requests');
    }
};
