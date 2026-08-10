<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('system_settings', 'group')) {
            Schema::table('system_settings', function (Blueprint $table) {
                $table->string('group', 50)->default('general')->after('id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('system_settings', 'group')) {
            Schema::table('system_settings', function (Blueprint $table) {
                $table->dropColumn('group');
            });
        }
    }
};
