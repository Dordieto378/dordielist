<?php

namespace App\Http\Controllers;

use App\Models\AnilistNotification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    private function abortIfViewer(Request $request): void
    {
        abort_if(optional($request->user()?->role)->role === 'Viewer', 403);
    }

    public function index(Request $request)
    {
        $this->abortIfViewer($request);

        $limit = (int) $request->query('limit', 20);
        $limit = max(20, $limit);

        $notifications = AnilistNotification::query()
            ->orderBy('is_read')
            ->orderByDesc('notified_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $total = AnilistNotification::count();
        $hasMore = $total > $notifications->count();
        $visibleUnreadNotificationIds = $notifications
            ->where('is_read', false)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $notifications->each(function (AnilistNotification $notification) use ($visibleUnreadNotificationIds) {
            $notification->show_unread_marker = in_array((int) $notification->id, $visibleUnreadNotificationIds, true);
        });

        return view('notifications.index', [
            'notifications' => $notifications,
            'limit' => $limit,
            'hasMore' => $hasMore,
            'visibleUnreadNotificationIds' => $visibleUnreadNotificationIds,
        ]);
    }

    public function markVisibleRead(Request $request)
    {
        $this->abortIfViewer($request);

        $ids = collect((array) $request->input('ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids !== []) {
            AnilistNotification::whereIn('id', $ids)->update([
                'is_read' => true,
            ]);
        }

        return response()->noContent();
    }
}
