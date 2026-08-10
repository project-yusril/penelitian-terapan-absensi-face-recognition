<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('geofences', function (Blueprint $table) {
            $table->id();
            $table->string('nama', 100);
            $table->decimal('latitude', 10, 8);
            $table->decimal('longitude', 11, 8);
            $table->integer('radius')->default(50);
            $table->string('gedung', 100)->nullable();
            $table->string('lantai', 10)->nullable();
            $table->unsignedBigInteger('prodi_id')->nullable();
            $table->enum('status', ['aktif', 'nonaktif'])->default('aktif');
            $table->timestamps();

            $table->foreign('prodi_id')->references('id')->on('prodis')->onDelete('set null');
            $table->index('prodi_id', 'idx_geofence_prodi');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('geofences');
    }
};
