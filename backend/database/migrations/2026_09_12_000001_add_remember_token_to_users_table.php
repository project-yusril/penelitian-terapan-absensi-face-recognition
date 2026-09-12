<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kolom `remember_token` adalah bagian standar skema auth Laravel (dipakai
 * fitur "remember me" pada guard session). Tabel `users` custom proyek ini
 * tidak mewarisinya, sehingga login dengan checkbox remember menghasilkan
 * SQLSTATE[42S22] (Unknown column 'remember_token').
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'remember_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->rememberToken()->after('last_login_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('users', 'remember_token')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropColumn('remember_token');
            });
        }
    }
};
