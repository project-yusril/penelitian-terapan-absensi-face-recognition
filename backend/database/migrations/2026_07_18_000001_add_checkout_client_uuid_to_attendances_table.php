<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->uuid('checkout_client_uuid')->nullable()->after('client_uuid');
            $table->unique(['user_id', 'checkout_client_uuid'], 'unique_user_checkout_client_uuid');
        });
    }

    public function down(): void
    {
        Schema::table('attendances', function (Blueprint $table) {
            $table->dropUnique('unique_user_checkout_client_uuid');
            $table->dropColumn('checkout_client_uuid');
        });
    }
};
