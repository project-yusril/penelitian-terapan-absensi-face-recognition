<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mata_kuliahs', function (Blueprint $table) {
            $table->id();
            $table->string('kode_mk', 20);
            $table->string('nama', 100);
            $table->integer('sks')->default(2);
            $table->unsignedBigInteger('semester_id');
            $table->unsignedBigInteger('prodi_id');
            $table->unsignedBigInteger('dosen_id')->nullable();
            $table->string('kelas', 10)->nullable();
            $table->integer('total_pertemuan')->default(16);
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('semester_id')->references('id')->on('semesters')->onDelete('cascade');
            $table->foreign('prodi_id')->references('id')->on('prodis')->onDelete('cascade');
            $table->foreign('dosen_id')->references('id')->on('users')->onDelete('set null');

            $table->unique(['kode_mk', 'semester_id', 'kelas'], 'unique_mk_semester_kelas');
            $table->index('semester_id', 'idx_mk_semester');
            $table->index('prodi_id', 'idx_mk_prodi');
            $table->index('dosen_id', 'idx_mk_dosen');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mata_kuliahs');
    }
};
