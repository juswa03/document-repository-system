<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     */
    public function index(Request $request)
    {
        return response()->json(
            $request->user()->notifications()->latest('created_at')->get()
        );
    }

    /**
     * PATCH /api/notifications/{notification}/read
     */
    public function markRead(Request $request, int $id)
    {
        $notification = $request->user()->notifications()->findOrFail($id);
        $notification->update(['is_read' => true]);

        return response()->json($notification);
    }
}
