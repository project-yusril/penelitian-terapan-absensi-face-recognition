<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Langganan Web Push (VAPID) per-device untuk dashboard.
 * Satu user bisa punya banyak subscription (beda browser/device).
 * `endpoint` unik karena itulah identitas langganan dari Push Service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('endpoint');
            // endpoint bisa sangat panjang & tak bisa di-unique-kan langsung di MySQL,
            // jadi unik berdasarkan SHA-256 dari endpoint.
            $table->char('endpoint_hash', 64)->unique('uniq_push_endpoint_hash');
            $table->string('public_key')->nullable();   // p256dh
            $table->string('auth_token')->nullable();    // auth
            $table->string('content_encoding', 50)->nullable();
            $table->string('user_agent')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('user_id', 'idx_push_user');

        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
