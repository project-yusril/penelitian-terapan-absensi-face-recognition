<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;

class NotificationService
{
    protected FcmService $fcmService;

    protected WebPushService $webPushService;

    public function __construct(FcmService $fcmService, WebPushService $webPushService)
    {
        $this->fcmService = $fcmService;
        $this->webPushService = $webPushService;
    }

    /**
     * Kirim notifikasi ke satu user
     */
    public function send(int $userId, string $type, string $title, string $message, array $data = []): Notification
    {
        $notification = Notification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $message,
            'data' => $data,
        ]);

        // Push FCM jika user punya fcm_token (mobile)
        $this->pushFcm($userId, $title, $message, $data);

        // Web Push ke browser dashboard (jika user punya subscription)
        $this->webPushService->sendToUser($userId, $title, $message, $data);

        return $notification;

    }

    /**
     * Kirim notifikasi ke multiple users
     */
    public function sendBulk(array $userIds, string $type, string $title, string $message, array $data = []): int
    {
        $count = 0;
        foreach ($userIds as $userId) {
            $this->send($userId, $type, $title, $message, $data);
            $count++;
        }

        return $count;
    }

    /**
     * Kirim notifikasi ke semua user dengan role tertentu
     */
    public function sendToRole(string $role, string $type, string $title, string $message, array $data = [], ?int $prodiId = null): int
    {
        $query = User::whereHas('roles', fn ($q) => $q->where('name', $role))
            ->where('status', 'aktif');

        if ($prodiId) {
            $query->where('prodi_id', $prodiId);
        }

        $userIds = $query->pluck('id')->toArray();

        return $this->sendBulk($userIds, $type, $title, $message, $data);
    }

    /**
     * Push FCM notification
     */
    protected function pushFcm(int $userId, string $title, string $message, array $data = []): void
    {
        $user = User::find($userId);

        if (! $user || ! $user->fcm_token) {
            return;
        }

        $this->fcmService->sendToDevice($user->fcm_token, $title, $message, $data);
    }
}
