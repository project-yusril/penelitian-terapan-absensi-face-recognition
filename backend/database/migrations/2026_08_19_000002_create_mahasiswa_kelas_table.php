<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mahasiswa_kelas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('kelas_id');
            $table->unsignedBigInteger('semester_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('kelas_id')->references('id')->on('kelas')->onDelete('cascade');
            $table->foreign('semester_id')->references('id')->on('semesters')->onDelete('cascade');

            $table->unique(['user_id', 'semester_id'], 'unique_mahasiswa_kelas_user_semester');
            $table->index('kelas_id', 'idx_mahasiswa_kelas_kelas');
            $table->index('semester_id', 'idx_mahasiswa_kelas_semester');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mahasiswa_kelas');
    }
};
