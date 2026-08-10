<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sp_records', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('semester_id');
            $table->enum('sp_level', ['sp1', 'sp2', 'sp3', 'do']);
            $table->string('nomor_surat', 50)->nullable();
            $table->date('tanggal_terbit')->nullable();
            $table->decimal('total_alpha_jam', 8, 2);

            $table->json('rincian_alpha')->nullable();

            $table->enum('status', ['draft', 'menunggu_kaprodi', 'menunggu_kajur', 'final', 'dibatalkan'])->default('draft');
            $table->unsignedBigInteger('generated_by')->nullable();
            $table->timestamp('generated_at')->nullable();

            $table->unsignedBigInteger('signed_kaprodi_by')->nullable();
            $table->timestamp('signed_kaprodi_at')->nullable();

            $table->unsignedBigInteger('signed_kajur_by')->nullable();
            $table->timestamp('signed_kajur_at')->nullable();

            $table->string('document_path', 255)->nullable();

            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('semester_id')->references('id')->on('semesters')->onDelete('cascade');
            $table->foreign('generated_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('signed_kaprodi_by')->references('id')->on('users')->onDelete('set null');
            $table->foreign('signed_kajur_by')->references('id')->on('users')->onDelete('set null');

            $table->index('user_id', 'idx_sp_user');
            $table->index('sp_level', 'idx_sp_level');
            $table->index('status', 'idx_sp_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sp_records');
    }
};
