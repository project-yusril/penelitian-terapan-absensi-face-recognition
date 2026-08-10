<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_permits', function (Blueprint $table) {
            $table->text('permit_token')->nullable()->after('token_hash');
            $table->text('encrypted_challenge')->nullable()->after('liveness_challenge');
            $table->string('liveness_challenge', 50)->nullable()->change();
        });

        DB::table('attendance_permits')
            ->whereNotNull('liveness_challenge')
            ->orderBy('id')
            ->eachById(function ($permit): void {
                DB::table('attendance_permits')->where('id', $permit->id)->update([
                    'encrypted_challenge' => Crypt::encryptString($permit->liveness_challenge),
                    'liveness_challenge' => null,
                ]);
            });

        Schema::table('notifications', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->unique()->after('id');
        });

        Schema::create('notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 191)->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 50);
            $table->string('title', 200);
            $table->text('body');
            $table->json('data')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
            $table->index(['processed_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_outbox');

        Schema::table('notifications', function (Blueprint $table) {
            $table->dropUnique(['idempotency_key']);
            $table->dropColumn('idempotency_key');
        });

        DB::table('attendance_permits')
            ->whereNotNull('encrypted_challenge')
            ->orderBy('id')
            ->eachById(function ($permit): void {
                DB::table('attendance_permits')->where('id', $permit->id)->update([
                    'liveness_challenge' => Crypt::decryptString($permit->encrypted_challenge),
                ]);
            });

        Schema::table('attendance_permits', function (Blueprint $table) {
            $table->dropColumn(['permit_token', 'encrypted_challenge']);
            $table->string('liveness_challenge', 50)->nullable(false)->change();
        });
    }
};
