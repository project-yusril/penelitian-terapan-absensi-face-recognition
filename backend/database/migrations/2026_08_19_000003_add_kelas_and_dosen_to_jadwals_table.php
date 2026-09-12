<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RENCANA 2 (1.3 + 1.4): pindahkan relasi kelas & dosen dari
 * `mata_kuliahs` ke `jadwals`.
 *
 * Kolom ditambahkan nullable supaya baris `jadwals` yang sudah ada tetap
 * valid; pengisian data dilakukan oleh script migrasi data (Phase 2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jadwals', function (Blueprint $table) {
            if (! Schema::hasColumn('jadwals', 'kelas_id')) {
                $table->unsignedBigInteger('kelas_id')->nullable()->after('mata_kuliah_id');
            }
            if (! Schema::hasColumn('jadwals', 'dosen_id')) {
                $table->unsignedBigInteger('dosen_id')->nullable()->after('kelas_id');
            }

            $table->foreign('kelas_id')->references('id')->on('kelas')->onDelete('set null');
            $table->foreign('dosen_id')->references('id')->on('users')->onDelete('set null');

            $table->index('kelas_id', 'idx_jadwal_kelas');
            $table->index('dosen_id', 'idx_jadwal_dosen');
        });
    }

    public function down(): void
    {
        Schema::table('jadwals', function (Blueprint $table) {
            $table->dropIndex(['kelas_id']);
            $table->dropIndex(['dosen_id']);
            $table->dropForeign(['kelas_id']);
            $table->dropForeign(['dosen_id']);
            $table->dropColumn(['kelas_id', 'dosen_id']);
        });
    }
};
