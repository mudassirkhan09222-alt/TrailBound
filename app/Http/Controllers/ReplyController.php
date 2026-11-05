<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Reply;
use App\Models\User;
use App\Services\NotificationService; // ✅ Import Notification Service
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ReplyController extends Controller
{
    protected $notificationService;

    // ✅ Constructor Injection for Notification Service
    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * POST /api/messages/{id}/replies
     * Reply Create Karna + Trigger Notification
     */
    public function store($messageId, Request $request): JsonResponse
    {
        $parentMessage = Message::find($messageId);
        
        if (!$parentMessage) {
            return response()->json(['status' => false, 'message' => 'Parent message not found'], 404);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $reply = Reply::create([
            'message_id' => $messageId,
            'user_id' => $request->user()->id,
            'content' => $request->content,
        ]);

        // ✅ Trigger Notification via Service
        // Parent message ke doosre participant ko notify karein (sender ya receiver jo current user na ho)
        $otherUserId = ($parentMessage->sender_id == $request->user()->id) 
                       ? $parentMessage->receiver_id 
                       : $parentMessage->sender_id;
                       
        $recipient = User::find($otherUserId);
        
        if ($recipient) {
            $this->notificationService->send(
                recipient: $recipient,
                actor: $request->user(),
                data: [
                    'type' => 'new_reply',
                    'title' => 'New Reply on Message',
                    'message' => substr($request->content, 0, 50), // Preview text
                    'related_id' => $reply->id,
                    'related_type' => 'reply',
                    'url' => '/messages/' . $parentMessage->id,
                ]
            );
        }

        // ✅ Safe Avatar URL Generation for Store Response
        $user = $request->user();
        $avatarUrl = null;
        if ($user->profile_picture) {
            $path = $user->profile_picture;
            if (str_starts_with($path, 'http')) {
                $avatarUrl = $path;
            } else {
                $avatarUrl = url('/storage/' . ltrim($path, '/'));
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Reply sent successfully',
            'data' => [
                'id' => $reply->id,
                'content' => $reply->content,
                'user_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'avatar_url' => $avatarUrl, // ✅ Fixed
                'time' => $reply->created_at->format('h:i A'),
            ]
        ], 201);
    }

    /**
     * GET /api/messages/{id}/replies
     * Kisi message ki saari replies dekhna (With Avatar URL)
     */
    public function index($messageId, Request $request): JsonResponse
    {
        $replies = Reply::where('message_id', $messageId)
            ->with('user:id,first_name,last_name,profile_picture')
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($reply) {
                // ✅ Safe Avatar URL Generation for List Response
                $avatarUrl = null;
                if ($reply->user && $reply->user->profile_picture) {
                    $path = $reply->user->profile_picture;
                    if (str_starts_with($path, 'http')) {
                        $avatarUrl = $path;
                    } else {
                        $avatarUrl = url('/storage/' . ltrim($path, '/'));
                    }
                }

                return [
                    'id' => $reply->id,
                    'content' => $reply->content,
                    'user' => [
                        'id' => $reply->user?->id,
                        'name' => trim(($reply->user->first_name ?? '') . ' ' . ($reply->user->last_name ?? '')),
                        'avatar_url' => $avatarUrl, // ✅ Fixed
                    ],
                    'time' => $reply->created_at->format('h:i A'),
                ];
            });

        return response()->json(['status' => true, 'data' => $replies]);
    }

    /**
     * PUT /api/replies/{id}
     * Reply Update Karna (With Avatar URL)
     */
    public function update($id, Request $request): JsonResponse
    {
        $reply = Reply::find($id);

        if (!$reply || $reply->user_id != $request->user()->id) {
            return response()->json(['status' => false, 'message' => 'Not authorized'], 403);
        }

        $validator = Validator::make($request->all(), [
            'content' => 'required|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $reply->update(['content' => $request->content]);

        // ✅ Safe Avatar URL Generation for Update Response
        $user = $request->user();
        $avatarUrl = null;
        if ($user->profile_picture) {
            $path = $user->profile_picture;
            if (str_starts_with($path, 'http')) {
                $avatarUrl = $path;
            } else {
                $avatarUrl = url('/storage/' . ltrim($path, '/'));
            }
        }

        return response()->json([
            'status' => true,
            'message' => 'Reply updated successfully',
            'data' => [
                'id' => $reply->id,
                'content' => $reply->content,
                'user_name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'avatar_url' => $avatarUrl, // ✅ Fixed
                'updated_at' => $reply->updated_at->format('h:i A'),
            ]
        ]);
    }

    /**
     * DELETE /api/replies/{id}
     * Reply Delete Karna
     */
    public function destroy($id, Request $request): JsonResponse
    {
        $reply = Reply::find($id);

        if (!$reply || $reply->user_id != $request->user()->id) {
            return response()->json(['status' => false, 'message' => 'Not authorized'], 403);
        }

        $reply->delete();

        return response()->json(['status' => true, 'message' => 'Reply deleted successfully']);
    }
}