<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * The caller's 50 most recent notifications, newest first, plus the
     * current unread count (the bell polls this).
     */
    public function index(Request $request)
    {
        $notifications = $request->user()->notifications()
            ->latest('created_at')
            ->limit(50)
            ->get();

        return response()->json([
            'data' => $notifications,
            'unread_count' => $notifications->where('is_read', false)->count(),
        ]);
    }

    /**
     * PATCH /api/notifications/read-all
     * Mark every unread notification for the caller as read.
     */
    public function markAllRead(Request $request)
    {
        $updated = $request->user()->notifications()
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * PATCH /api/notifications/{notification}
     */
    public function markRead(Request $request, int $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->update(['is_read' => true]);

        return response()->json($notification);
    }
}
