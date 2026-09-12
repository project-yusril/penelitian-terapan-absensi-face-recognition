<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kelas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prodi_id');
            $table->unsignedBigInteger('semester_id');
            $table->enum('tingkat', ['1', '2', '3', '4', '5']);
            $table->string('nama', 5);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('prodi_id')->references('id')->on('prodis')->onDelete('cascade');
            $table->foreign('semester_id')->references('id')->on('semesters')->onDelete('cascade');

            $table->unique(['prodi_id', 'semester_id', 'tingkat', 'nama'], 'unique_kelas_prodi_semester_tingkat_nama');
            $table->index('prodi_id', 'idx_kelas_prodi');
            $table->index('semester_id', 'idx_kelas_semester');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kelas');
    }
};
