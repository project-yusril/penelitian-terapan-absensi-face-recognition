<?php

namespace App\Services;

use App\Models\PushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush;

/**
 * Web Push (VAPID) — pengiriman notifikasi push ke browser dashboard.
 *
 * Setup:
 * 1. `php artisan webpush:vapid` untuk generate sepasang kunci VAPID.
 * 2. Salin VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT ke .env.
 * 3. Browser men-subscribe via PushSubscriptionController@store (service worker).
 */
class WebPushService
{
    /**
     * Apakah VAPID sudah dikonfigurasi (kunci tersedia).
     */
    public function isConfigured(): bool
    {
        return ! empty(config('services.webpush.public_key'))
            && ! empty(config('services.webpush.private_key'));
    }

    /**
     * Kirim push ke seluruh subscription milik satu user.
     * Subscription yang ditolak permanen (404/410) dihapus otomatis.
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        if (! $this->isConfigured()) {
            Log::debug('WebPush: VAPID belum dikonfigurasi, push dilewati.', ['title' => $title]);

            return;
        }

        $subscriptions = PushSubscription::where('user_id', $userId)->get();

        if ($subscriptions->isEmpty()) {
            return;
        }

        $webPush = $this->makeClient();

        $payload = json_encode([
            'title' => $title,
            'body' => $body,
            'data' => $data,
        ]);

        $byEndpoint = [];
        foreach ($subscriptions as $sub) {
            $byEndpoint[$sub->endpoint] = $sub;

            $webPush->queueNotification(
                Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->public_key,
                    'authToken' => $sub->auth_token,
                    'contentEncoding' => $sub->content_encoding ?: 'aesgcm',
                ]),
                $payload
            );
        }

        foreach ($webPush->flush() as $report) {
            $endpoint = $report->getRequest()->getUri()->__toString();
            $sub = $byEndpoint[$endpoint] ?? null;

            if ($report->isSuccess()) {
                $sub?->forceFill(['last_used_at' => now()])->save();

                continue;
            }

            // 404/410 = subscription kedaluwarsa → hapus agar tidak menumpuk.
            if ($report->isSubscriptionExpired()) {
                $sub?->delete();

                continue;
            }

            Log::warning('WebPush: gagal kirim', [
                'endpoint' => substr($endpoint, 0, 60).'...',
                'reason' => $report->getReason(),
            ]);
        }
    }

    private function makeClient(): WebPush
    {
        return new WebPush([
            'VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ],
        ]);
    }
}
