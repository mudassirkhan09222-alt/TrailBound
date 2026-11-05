<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostComment;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class PostCommentController extends Controller
{
    // GET /api/posts/{postId}/comments
    public function index(Request $request, $postId)
    {
        if (!Post::whereKey($postId)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $comments = PostComment::where('post_id', $postId)
            ->with('user:id,first_name,last_name,profile_picture')
            ->withCount(['replies', 'likes'])
            ->latest()
            ->paginate(20);

        $comments->getCollection()->transform(function ($comment) use ($request) {
            return $this->formatComment($comment, $request->user()?->id);
        });

        return response()->json([
            'status' => true,
            'message' => 'Comments fetched successfully',
            'data' => $comments,
        ]);
    }

    // POST /api/posts/{postId}/comments
    public function store(Request $request, $postId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $post = Post::with('user')->find($postId);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comment = PostComment::create([
            'post_id' => $post->id,
            'user_id' => $user->id,
            'comment' => $request->comment,
        ]);

        $actorName = trim(
            ($user->first_name ?? '') . ' ' .
            ($user->last_name ?? '')
        );

        // Notify the post owner.
        if ($post->user) {
            app(NotificationService::class)->send(
                $post->user,
                [
                    'type' => 'post_commented',
                    'title' => 'New Comment',
                    'message' => $actorName . ' commented: "' .
                        Str::limit($comment->comment, 150) . '"',
                    'comment_text' => $comment->comment,
                    'comment_id' => $comment->id,
                    'actor_id' => $user->id,
                    'actor_name' => $actorName,
                    'actor_avatar' => $user->profile_picture,
                    'related_id' => $post->id,
                    'related_type' => 'post',
                    'url' => '/posts/' . $post->id,
                ],
                $user
            );
        }

        $comment->load('user:id,first_name,last_name,profile_picture');
        $comment->loadCount(['replies', 'likes']);

        return response()->json([
            'status' => true,
            'message' => 'Comment created successfully',
            'data' => $this->formatComment($comment, $user->id),
        ], 201);
    }

    // GET /api/comments/{commentId}
    public function show(Request $request, $commentId)
    {
        $comment = PostComment::with(
            'user:id,first_name,last_name,profile_picture'
        )->withCount(['replies', 'likes'])->find($commentId);

        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        return response()->json([
            'status' => true,
            'message' => 'Comment fetched successfully',
            'data' => $this->formatComment($comment, $request->user()?->id),
        ]);
    }

    // PUT /api/comments/{commentId}
    public function update(Request $request, $commentId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $comment = PostComment::find($commentId);

        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        if ((int) $comment->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You can only update your own comment',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'comment' => 'required|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $comment->update([
            'comment' => $request->comment,
        ]);

        $comment->load('user:id,first_name,last_name,profile_picture');
        $comment->loadCount(['replies', 'likes']);

        return response()->json([
            'status' => true,
            'message' => 'Comment updated successfully',
            'data' => $this->formatComment($comment, $user->id),
        ]);
    }

    // DELETE /api/comments/{commentId}
    public function destroy(Request $request, $commentId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $comment = PostComment::find($commentId);

        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        if ((int) $comment->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You can only delete your own comment',
            ], 403);
        }

        $comment->delete();

        return response()->json([
            'status' => true,
            'message' => 'Comment deleted successfully',
        ]);
    }

    private function formatComment(PostComment $comment, $authUserId = null)
    {
        $profilePicture = $comment->user?->profile_picture;

        return [
            'id' => $comment->id,
            'post_id' => $comment->post_id,
            'user_id' => $comment->user_id,
            'comment' => $comment->comment,
            'user' => $comment->user ? [
                'id' => $comment->user->id,
                'name' => trim(
                    ($comment->user->first_name ?? '') . ' ' .
                    ($comment->user->last_name ?? '')
                ),
                'profile_picture' => $this->formatImageUrl($profilePicture),
            ] : null,
            'reply_count' => $comment->replies_count ?? 0,
            'like_count' => $comment->likes_count ?? 0,
            'is_liked_by_me' => $authUserId
                ? $comment->likes()->where('user_id', $authUserId)->exists()
                : false,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
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
