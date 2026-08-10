<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $perPage = $this->resolvePerPage($request, 15);
        $filter = $request->string('filter', 'all')->toString();

        $items = Notification::where('user_id', $request->user()->id)
            ->when($filter === 'unread', fn ($q) => $q->where('is_read', false))
            ->orderByDesc('created_at')
            ->paginate($perPage)
            ->withQueryString()
            ->through(fn (Notification $n) => [
                'id' => $n->id,
                'title' => $n->title,
                'body' => $n->body,
                'type' => $n->type,
                'is_read' => $n->is_read,
                'created_at' => $n->created_at?->diffForHumans(),
            ]);

        return Inertia::render('Notifications/Index', [
            'items' => $items,
            'filters' => ['filter' => $filter, 'per_page' => $perPage],
        ]);
    }

    public function markRead(Request $request, Notification $notification): RedirectResponse
    {
        abort_unless($notification->user_id === $request->user()->id, 403);
        $notification->update(['is_read' => true, 'read_at' => now()]);

        return back();
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        Notification::where('user_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true, 'read_at' => now()]);

        return back()->with('success', 'Semua notifikasi ditandai dibaca.');
    }
}
