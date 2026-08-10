<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * List notifikasi user yang login
     */
    public function index(Request $request): JsonResponse
    {
        $query = Notification::where('user_id', $request->user()->id)
            ->orderByDesc('created_at');

        if ($request->filled('unread_only') && $request->unread_only) {
            $query->unread();
        }

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $data = $query->paginate($this->resolvePerPage($request, 20));

        return $this->paginated($data);
    }

    /**
     * Jumlah notifikasi belum dibaca
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $count = Notification::where('user_id', $request->user()->id)
            ->unread()
            ->count();

        return $this->success(['unread_count' => $count]);
    }

    /**
     * Tandai satu notifikasi sebagai dibaca
     */
    public function markAsRead(Request $request, int $id): JsonResponse
    {
        $notification = Notification::where('user_id', $request->user()->id)
            ->findOrFail($id);

        $notification->update([
            'is_read' => true,
            'read_at' => now(),
        ]);

        return $this->success(message: 'Notifikasi ditandai sudah dibaca');
    }

    /**
     * Tandai semua notifikasi sebagai dibaca
     */
    public function markAllAsRead(Request $request): JsonResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->unread()
            ->update([
                'is_read' => true,
                'read_at' => now(),
            ]);

        return $this->success(message: 'Semua notifikasi ditandai sudah dibaca');
    }
}
