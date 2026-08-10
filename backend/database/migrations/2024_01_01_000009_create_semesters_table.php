<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('semesters', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tahun_ajaran_id');
            $table->enum('nama', ['Ganjil', 'Genap']);
            $table->string('kode', 20)->unique();
            $table->date('tanggal_mulai');
            $table->date('tanggal_selesai');
            $table->enum('status', ['aktif', 'nonaktif'])->default('nonaktif');
            $table->timestamps();

            $table->foreign('tahun_ajaran_id')->references('id')->on('tahun_ajarans')->onDelete('cascade');
            $table->index('tahun_ajaran_id', 'idx_semester_tahun');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('semesters');
    }
};
