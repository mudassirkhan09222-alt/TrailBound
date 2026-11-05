<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class MessageController extends Controller
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * GET /api/messages
     * Screen 1: Message List (Inbox)
     */
    public function index(Request $request): JsonResponse
    {
        $userId = $request->user()->id;

        $latestMessageIds = Message::selectRaw('MAX(id) as max_id')
            ->where(function ($query) use ($userId) {
                $query->where('sender_id', $userId)->orWhere('receiver_id', $userId);
            })
            ->groupBy(
                DB::raw("LEAST(sender_id, receiver_id)"), 
                DB::raw("GREATEST(sender_id, receiver_id)")
            )
            ->pluck('max_id');

        if ($latestMessageIds->isEmpty()) {
            return response()->json(['status' => true, 'data' => []]);
        }

        $conversations = Message::whereIn('id', $latestMessageIds)
            ->orderByDesc('created_at')
            ->get()
            ->map(function ($msg) use ($userId) {
                $otherUserId = $msg->sender_id == $userId ? $msg->receiver_id : $msg->sender_id;
                $otherUser = User::find($otherUserId);
                
                $avatarUrl = null;
                if ($otherUser && $otherUser->profile_picture) {
                    $path = $otherUser->profile_picture;
                    $avatarUrl = str_starts_with($path, 'http') ? $path : url('/storage/' . ltrim($path, '/'));
                }

                $unreadCount = Message::where('sender_id', $otherUserId)
                                      ->where('receiver_id', $userId)
                                      ->where('is_read', false)
                                      ->count();

                return [
                    'user' => [
                        'id' => $otherUser?->id,
                        'name' => trim(($otherUser->first_name ?? '') . ' ' . ($otherUser->last_name ?? '')),
                        'avatar_url' => $avatarUrl,
                        'status' => $otherUser?->is_active ? 'Available' : 'Unavailable',
                    ],
                    'last_message' => $msg->message,
                    'time' => $msg->created_at->diffForHumans(),
                    'unread_count' => $unreadCount,
                ];
            });

        return response()->json([
            'status' => true,
            'data' => $conversations->values(),
        ]);
    }

    /**
     * GET /api/messages/users
     * Screen 2 & 3: Create Message (User Selection List)
     */
    public function getUsersForChat(Request $request): JsonResponse
    {
        $currentUserId = $request->user()->id;
        
        $users = User::where('id', '!=', $currentUserId)
                     ->where('is_active', true)
                     ->select('id', 'first_name', 'last_name', 'profile_picture', 'is_active')
                     ->latest()
                     ->get()
                     ->map(function ($user) {
                         $avatarUrl = null;
                         if ($user->profile_picture) {
                             $path = $user->profile_picture;
                             $avatarUrl = str_starts_with($path, 'http') ? $path : url('/storage/' . ltrim($path, '/'));
                         }

                         return [
                             'id' => $user->id,
                             'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                             'avatar_url' => $avatarUrl,
                             'status' => $user->is_active ? 'Available' : 'Unavailable',
                         ];
                     });

        return response()->json([
            'status' => true,
            'data' => $users,
        ]);
    }

    /**
     * GET /api/messages/{userId}
     * Screen 4: Chat History with BOTH Users' Profiles
     */
    public function showConversation($userId, Request $request): JsonResponse
    {
        $myId = $request->user()->id;
        $currentUser = $request->user(); // ✅ Current user fetch karein

        $targetUser = User::find($userId);
        if (!$targetUser) {
            return response()->json(['status' => false, 'message' => 'User not found'], 404);
        }

        // ✅ Target User (Samne wala) Avatar Logic
        $targetAvatarUrl = null;
        if ($targetUser->profile_picture) {
            $path = $targetUser->profile_picture;
            $targetAvatarUrl = str_starts_with($path, 'http') ? $path : url('/storage/' . ltrim($path, '/'));
        }

        // ✅ Current User (Khud) Avatar Logic
        $currentAvatarUrl = null;
        if ($currentUser->profile_picture) {
            $path = $currentUser->profile_picture;
            $currentAvatarUrl = str_starts_with($path, 'http') ? $path : url('/storage/' . ltrim($path, '/'));
        }

        // ✅ Saari Messages Fetch Karein (Chronological Order)
        $messages = Message::where(function ($query) use ($myId, $userId) {
                $query->where('sender_id', $myId)->where('receiver_id', $userId);
            })
            ->orWhere(function ($query) use ($myId, $userId) {
                $query->where('sender_id', $userId)->where('receiver_id', $myId);
            })
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'sender_id' => $msg->sender_id,
                    'message' => $msg->message,
                    'is_me' => $msg->sender_id == auth()->id(),
                    'time' => $msg->created_at->format('h:i A'),
                    'date' => $msg->created_at->toDateString(),
                ];
            });

        // Mark messages as read
        Message::where('sender_id', $userId)
               ->where('receiver_id', $myId)
               ->where('is_read', false)
               ->update(['is_read' => true]);

        return response()->json([
            'status' => true,
            'data' => [
                // ✅ Samne wale user ki details (Header ke liye)
                'user' => [
                    'id' => $targetUser->id,
                    'name' => trim(($targetUser->first_name ?? '') . ' ' . ($targetUser->last_name ?? '')),
                    'avatar_url' => $targetAvatarUrl,
                ],
                // ✅ Current user ki details (Apni bubble dikhane ke liye)
                'me' => [
                    'id' => $currentUser->id,
                    'name' => trim(($currentUser->first_name ?? '') . ' ' . ($currentUser->last_name ?? '')),
                    'avatar_url' => $currentAvatarUrl,
                ],
                // ✅ Puri conversation history
                'messages' => $messages,
            ],
        ]);
    }

    /**
     * POST /api/messages
     * Send New Message + Trigger Notification
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'receiver_id' => 'required|exists:users,id',
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $message = Message::create([
            'sender_id' => $request->user()->id,
            'receiver_id' => $request->receiver_id,
            'message' => $request->message,
            'is_read' => false,
        ]);

        // ✅ Trigger Notification via Service
        $receiver = User::find($request->receiver_id);
        if ($receiver) {
            $this->notificationService->send(
                recipient: $receiver,
                actor: $request->user(),
                data: [
                    'type' => 'new_message',
                    'title' => 'New Message',
                    'message' => substr($request->message, 0, 50),
                    'related_id' => $message->id,
                    'related_type' => 'message',
                    'url' => '/messages/' . $request->user()->id,
                ]
            );
        }

        // ✅ Safe Avatar URL Generation for Store Response
        $senderAvatarUrl = null;
        if ($request->user()->profile_picture) {
            $path = $request->user()->profile_picture;
            $senderAvatarUrl = str_starts_with($path, 'http') ? $path : url('/storage/' . ltrim($path, '/'));
        }

        return response()->json([
            'status' => true,
            'message' => 'Message sent successfully',
            'data' => [
                'id' => $message->id,
                'sender_id' => $message->sender_id,
                'message' => $message->message,
                'is_me' => true,
                'avatar_url' => $senderAvatarUrl,
                'time' => $message->created_at->format('h:i A'),
            ]
        ], 201);
    }

    /**
     * PUT /api/messages/{id}
     * Update/Edit Message
     */
    public function update($id, Request $request): JsonResponse
    {
        $message = Message::find($id);

        if (!$message || $message->sender_id != $request->user()->id) {
            return response()->json([
                'status' => false, 
                'message' => 'Not authorized to edit this message'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json(['status' => false, 'errors' => $validator->errors()], 422);
        }

        $message->update(['message' => $request->message]);

        return response()->json([
            'status' => true,
            'message' => 'Message updated successfully',
            'data' => [
                'id' => $message->id,
                'message' => $message->message,
                'updated_at' => $message->updated_at->format('h:i A'),
            ]
        ]);
    }

    /**
     * DELETE /api/messages/{id}
     * Delete Message
     */
    public function destroy($id, Request $request): JsonResponse
    {
        $message = Message::find($id);

        if (!$message || $message->sender_id != $request->user()->id) {
            return response()->json(['status' => false, 'message' => 'Not authorized'], 403);
        }

        $message->delete();

        return response()->json(['status' => true, 'message' => 'Message deleted']);
    }
}