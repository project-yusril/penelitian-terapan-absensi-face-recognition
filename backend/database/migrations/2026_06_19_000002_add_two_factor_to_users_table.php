<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahkan kolom 2FA opsional ke users.
 * - `two_factor_secret` : Base32 secret hasil generate Google2FA (terenkripsi).
 * - `two_factor_confirmed_at` : timestamp aktivasi (null = 2FA belum aktif).
 *
 * Dipakai oleh super_admin untuk lapisan keamanan tambahan, tapi opsional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            if (! Schema::hasColumn('users', 'two_factor_secret')) {
                $t->text('two_factor_secret')->nullable()->after('password');
            }
            if (! Schema::hasColumn('users', 'two_factor_confirmed_at')) {
                $t->timestamp('two_factor_confirmed_at')->nullable()->after('two_factor_secret');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $t) {
            foreach (['two_factor_secret', 'two_factor_confirmed_at'] as $col) {
                if (Schema::hasColumn('users', $col)) {
                    $t->dropColumn($col);
                }
            }
        });
    }
};
