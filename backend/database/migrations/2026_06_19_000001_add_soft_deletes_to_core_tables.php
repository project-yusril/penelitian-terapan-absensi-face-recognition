<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan soft delete (deleted_at) ke tabel inti agar penghapusan
 * dapat di-trace di Audit Trail dan dipulihkan bila perlu (akhir
 * semester / kekeliruan operasional).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['users', 'mata_kuliahs', 'jadwals'] as $table) {
            if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->softDeletes();
                });
            }
        }
    }

    public function down(): void
    {
        foreach (['users', 'mata_kuliahs', 'jadwals'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'deleted_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->dropSoftDeletes();
                });
            }
        }
    }
};
