<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prodi_settings', function (Blueprint $table) {
            $table->unsignedInteger('gps_max_age_seconds')->default(10)->after('gps_accuracy_minimum');
        });
    }

    public function down(): void
    {
        Schema::table('prodi_settings', function (Blueprint $table) {
            $table->dropColumn('gps_max_age_seconds');
        });
    }
};
