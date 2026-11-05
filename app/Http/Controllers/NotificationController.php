<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;

class NotificationController extends Controller
{
    /**
     * GET /api/notifications
     * Login user ki notifications list.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $notifications = $user->notifications()
            ->latest()
            ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Notifications fetched successfully.',
            'unread_count' => $user->unreadNotifications()->count(),
            'data' => $notifications,
        ]);
    }

    /**
     * GET /api/notifications/unread-count
     */
    public function unreadCount(Request $request)
    {
        return response()->json([
            'status' => true,
            'message' => 'Unread notifications count fetched successfully.',
            'unread_count' => $request->user()
                ->unreadNotifications()
                ->count(),
        ]);
    }

    /**
     * PATCH /api/notifications/{id}/read
     * Sirf apni notification read kar sakta hai.
     */
    public function markAsRead(Request $request, string $id)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if (!$notification) {
            return response()->json([
                'status' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        if ($notification->read_at === null) {
            $notification->markAsRead();
        }

        return response()->json([
            'status' => true,
            'message' => 'Notification marked as read.',
            'data' => $notification,
        ]);
    }

    /**
     * PATCH /api/notifications/read-all
     * Login user ki saari unread notifications read.
     */
    public function markAllAsRead(Request $request)
    {
        $updated = $request->user()
            ->unreadNotifications()
            ->update([
                'read_at' => now(),
            ]);

        return response()->json([
            'status' => true,
            'message' => 'All notifications marked as read.',
            'updated_count' => $updated,
        ]);
    }

    /**
     * DELETE /api/notifications/{id}
     * Sirf apni notification delete kar sakta hai.
     */
    public function destroy(Request $request, string $id)
    {
        $notification = $request->user()
            ->notifications()
            ->where('id', $id)
            ->first();

        if (!$notification) {
            return response()->json([
                'status' => false,
                'message' => 'Notification not found.',
            ], 404);
        }

        $notification->delete();

        return response()->json([
            'status' => true,
            'message' => 'Notification deleted successfully.',
        ]);
    }
}