<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\PushSubscription;
use App\Services\WebPushService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint langganan Web Push untuk dashboard.
 * Dipanggil dari composable useWebPush (service worker) via fetch/axios.
 */
class PushSubscriptionController extends Controller
{
    /**
     * Simpan / perbarui subscription milik user yang sedang login.
     * Idempotent berdasarkan `endpoint` (unique).
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
            'contentEncoding' => ['nullable', 'string', 'max:50'],
        ]);

        $subscription = PushSubscription::updateOrCreate(
            ['endpoint_hash' => hash('sha256', $data['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $data['endpoint'],
                'public_key' => $data['keys']['p256dh'],

                'auth_token' => $data['keys']['auth'],
                'content_encoding' => $data['contentEncoding'] ?? 'aesgcm',
                'user_agent' => substr((string) $request->userAgent(), 0, 255),
                'last_used_at' => now(),
            ]
        );

        return response()->json([
            'success' => true,
            'id' => $subscription->id,
        ]);
    }

    /**
     * Hapus subscription saat user menonaktifkan / unsubscribe di browser.
     */
    public function destroy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
        ]);

        PushSubscription::where('user_id', $request->user()->id)
            ->where('endpoint_hash', hash('sha256', $data['endpoint']))
            ->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Kirim notifikasi uji ke seluruh device user (verifikasi setup).
     */
    public function test(Request $request, WebPushService $webPush): JsonResponse
    {
        $webPush->sendToUser(
            $request->user()->id,
            'Notifikasi Uji',
            'Web Push berfungsi. Anda akan menerima pemberitahuan dari dashboard.',
            ['url' => route('dashboard')],
        );

        return response()->json(['success' => true]);
    }
}
