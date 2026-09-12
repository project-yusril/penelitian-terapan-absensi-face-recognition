<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * RENCANA 2 (5.4): hapus tabel pivot lama `mahasiswa_mata_kuliah`.
 *
 * KRS kini diturunkan dari `mahasiswa_kelas` → kelas → jadwal. Seluruh query
 * sudah dialihkan di Phase 4 & 5.1–5.2; tabel ini tidak lagi dirujuk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mahasiswa_mata_kuliah');
    }

    public function down(): void
    {
        Schema::create('mahasiswa_mata_kuliah', function (\Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('mata_kuliah_id');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('mata_kuliah_id')->references('id')->on('mata_kuliahs')->onDelete('cascade');
            $table->unique(['user_id', 'mata_kuliah_id'], 'unique_mhs_mk');
        });
    }
};
