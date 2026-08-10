<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prodi_settings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('prodi_id');

            // Toleransi waktu
            $table->integer('toleransi_masuk_menit')->default(15);
            $table->integer('batas_terlambat_persen')->default(50);
            $table->integer('toleransi_pulang_menit')->default(15);

            // SP thresholds (jam)
            $table->integer('sp1_jam_mulai')->default(16);
            $table->integer('sp1_jam_akhir')->default(31);
            $table->integer('sp2_jam_mulai')->default(32);
            $table->integer('sp2_jam_akhir')->default(37);
            $table->integer('sp3_jam_mulai')->default(38);
            $table->integer('sp3_jam_akhir')->default(45);
            $table->integer('do_jam_mulai')->default(46);

            // Face recognition settings
            $table->decimal('face_threshold', 5, 3)->default(1.000);
            $table->integer('liveness_challenge_count')->default(1);
            $table->integer('liveness_timeout_seconds')->default(10);
            $table->integer('max_failed_attempts')->default(5);

            // Geofence settings
            $table->integer('default_radius_meter')->default(50);
            $table->integer('gps_accuracy_minimum')->default(20);
            $table->boolean('allow_offline_attendance')->default(true);
            $table->integer('offline_sync_timeout_menit')->default(30);

            // Warning settings
            $table->integer('sp_warning_percentage')->default(80);

            $table->timestamps();

            $table->foreign('prodi_id')->references('id')->on('prodis')->onDelete('cascade');
            $table->unique('prodi_id', 'unique_prodi_setting');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prodi_settings');
    }
};
