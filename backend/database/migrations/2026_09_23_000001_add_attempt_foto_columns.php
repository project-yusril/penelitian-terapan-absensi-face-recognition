<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FOTO ATTEMPT BERISIKO (keputusan diskusi 23 Sep 2026):
 * foto check-in/checkout TIDAK disimpan untuk semua attempt (16 GB/semester
 * pada 500 mahasiswa), hanya untuk attempt berisiko — gagal face match,
 * face_distance mendekati threshold, mock location, atau offline sync.
 *
 * Kolom path di attendance_logs (satu foto per attempt) dan attendances
 * (foto final per status). Purge 30 hari via command tersendiri.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_logs', function (Blueprint $table) {
            if (! Schema::hasColumn('attendance_logs', 'foto_path')) {
                $table->string('foto_path', 255)->nullable()->after('metadata');
            }

            if (! Schema::hasColumn('attendance_logs', 'foto_reason')) {
                $table->string('foto_reason', 30)->nullable()->after('foto_path');
            }
        });

        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'checkin_foto_path')) {
                $table->string('checkin_foto_path', 255)->nullable()->after('checkin_device');
            }

            if (! Schema::hasColumn('attendances', 'checkout_foto_path')) {
                $table->string('checkout_foto_path', 255)->nullable()->after('checkout_device');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            foreach (['checkout_foto_path', 'checkin_foto_path'] as $column) {
                if (Schema::hasColumn('attendances', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('attendance_logs', function (Blueprint $table) {
            foreach (['foto_reason', 'foto_path'] as $column) {
                if (Schema::hasColumn('attendance_logs', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
