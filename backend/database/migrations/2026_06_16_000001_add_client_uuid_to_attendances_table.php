<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M-02: kolom idempotency untuk offline sync.
 *
 * `client_uuid` adalah UUID yang di-generate di mobile saat user check-in/out
 * offline. Saat batch dikirim ulang (retry), backend pakai UUID ini untuk
 * mendeteksi duplicate record dan mengembalikan `attendance_id` yang sama.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (! Schema::hasColumn('attendances', 'client_uuid')) {
                $table->uuid('client_uuid')->nullable()->after('id');
                $table->unique(['user_id', 'client_uuid'], 'unique_user_client_uuid');
            }
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            if (Schema::hasColumn('attendances', 'client_uuid')) {
                $table->dropUnique('unique_user_client_uuid');
                $table->dropColumn('client_uuid');
            }
        });
    }
};
