<?php

namespace App\Http\Controllers;

use App\Models\PostComment;
use App\Models\CommentReply;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class CommentReplyController extends Controller
{
    // GET /api/comments/{commentId}/replies
    public function index(Request $request, $commentId)
    {
        if (!PostComment::whereKey($commentId)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $replies = CommentReply::where('comment_id', $commentId)
            ->with('user:id,first_name,last_name,profile_picture')
            ->latest()
            ->paginate(20);

        $replies->getCollection()->transform(function ($reply) {
            return $this->formatReply($reply);
        });

        return response()->json([
            'status' => true,
            'message' => 'Replies fetched successfully',
            'data' => $replies,
        ]);
    }

    // POST /api/comments/{commentId}/replies
    public function store(Request $request, $commentId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $comment = PostComment::with('user')->find($commentId);

        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reply' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $reply = CommentReply::create([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
            'reply' => $request->reply,
        ]);

        $actorName = trim(
            ($user->first_name ?? '') . ' ' .
            ($user->last_name ?? '')
        );

        // Notify the owner of the original comment.
        if ($comment->user) {
            app(NotificationService::class)->send(
                $comment->user,
                [
                    'type' => 'comment_replied',
                    'title' => 'New Reply',
                    'message' => $actorName . ' replied: "' .
                        Str::limit($reply->reply, 150) . '"',
                    'reply_text' => $reply->reply,
                    'reply_id' => $reply->id,
                    'comment_id' => $comment->id,
                    'actor_id' => $user->id,
                    'actor_name' => $actorName,
                    'actor_avatar' => $user->profile_picture,
                    'related_id' => $comment->post_id,
                    'related_type' => 'post',
                    'url' => '/posts/' . $comment->post_id,
                ],
                $user
            );
        }

        $reply->load('user:id,first_name,last_name,profile_picture');

        return response()->json([
            'status' => true,
            'message' => 'Reply created successfully',
            'data' => $this->formatReply($reply),
        ], 201);
    }

    // GET /api/comment-replies/{replyId}
    public function show($replyId)
    {
        $reply = CommentReply::with(
            'user:id,first_name,last_name,profile_picture'
        )->find($replyId);

        if (!$reply) {
            return response()->json([
                'status' => false,
                'message' => 'Reply not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Reply fetched successfully',
            'data' => $this->formatReply($reply),
        ]);
    }

    // PUT /api/comment-replies/{replyId}
    public function update(Request $request, $replyId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $reply = CommentReply::find($replyId);

        if (!$reply) {
            return response()->json([
                'status' => false,
                'message' => 'Reply not found',
            ], 404);
        }

        if ((int) $reply->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You can only update your own reply',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'reply' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $reply->update([
            'reply' => $request->reply,
        ]);

        $reply->load('user:id,first_name,last_name,profile_picture');

        return response()->json([
            'status' => true,
            'message' => 'Reply updated successfully',
            'data' => $this->formatReply($reply),
        ]);
    }

    // DELETE /api/comment-replies/{replyId}
    public function destroy(Request $request, $replyId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $reply = CommentReply::find($replyId);

        if (!$reply) {
            return response()->json([
                'status' => false,
                'message' => 'Reply not found',
            ], 404);
        }

        if ((int) $reply->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You can only delete your own reply',
            ], 403);
        }

        $reply->delete();

        return response()->json([
            'status' => true,
            'message' => 'Reply deleted successfully',
        ]);
    }

    private function formatReply(CommentReply $reply)
    {
        $profilePicture = $reply->user?->profile_picture;

        return [
            'id' => $reply->id,
            'comment_id' => $reply->comment_id,
            'user_id' => $reply->user_id,
            'reply' => $reply->reply,
            'user' => $reply->user ? [
                'id' => $reply->user->id,
                'name' => trim(
                    ($reply->user->first_name ?? '') . ' ' .
                    ($reply->user->last_name ?? '')
                ),
                'profile_picture' => $this->formatImageUrl($profilePicture),
            ] : null,
            'created_at' => $reply->created_at,
            'updated_at' => $reply->updated_at,
        ];
    }

    private function formatImageUrl($path)
    {
        if (!$path) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return asset('storage/' . ltrim($path, '/'));
    }
}
