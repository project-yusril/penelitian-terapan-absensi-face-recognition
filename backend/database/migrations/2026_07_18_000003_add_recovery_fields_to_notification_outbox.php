<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt_count')->default(0)->after('processed_at');
            $table->timestamp('next_attempt_at')->nullable()->after('attempt_count');
            $table->text('last_error')->nullable()->after('next_attempt_at');
            $table->uuid('lock_token')->nullable()->after('last_error');
            $table->timestamp('locked_at')->nullable()->after('lock_token');
            $table->timestamp('failed_at')->nullable()->after('locked_at');
            $table->index(['processed_at', 'failed_at', 'next_attempt_at', 'locked_at'], 'notification_outbox_eligibility_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_outbox', function (Blueprint $table) {
            $table->dropIndex('notification_outbox_eligibility_index');
            $table->dropColumn(['attempt_count', 'next_attempt_at', 'last_error', 'lock_token', 'locked_at', 'failed_at']);
        });
    }
};
