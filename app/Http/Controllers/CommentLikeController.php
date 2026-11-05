<?php

namespace App\Http\Controllers;

use App\Models\PostComment;
use App\Models\CommentLike;
use Illuminate\Http\Request;

class CommentLikeController extends Controller
{
    // POST /api/comments/{commentId}/like
    public function store(Request $request, $commentId)
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

        $like = CommentLike::firstOrCreate([
            'comment_id' => $comment->id,
            'user_id' => $user->id,
        ]);

        $like->load('user:id,first_name,last_name,profile_picture');

        $profilePicture = $like->user?->profile_picture;

        return response()->json([
            'status' => true,
            'message' => $like->wasRecentlyCreated
                ? 'Comment liked successfully'
                : 'You have already liked this comment',
            'data' => [
                'comment_id' => $comment->id,
                'like_count' => $comment->likes()->count(),
                'is_liked_by_me' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => trim(
                        ($user->first_name ?? '') . ' ' .
                        ($user->last_name ?? '')
                    ),
                    'profile_picture' => $profilePicture
                        ? (preg_match('/^https?:\/\//i', $profilePicture)
                            ? $profilePicture
                            : asset('storage/' . ltrim($profilePicture, '/')))
                        : null,
                ],
            ],
        ], $like->wasRecentlyCreated ? 201 : 200);
    }

    // DELETE /api/comments/{commentId}/like
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

        $like = CommentLike::where('comment_id', $comment->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$like) {
            return response()->json([
                'status' => false,
                'message' => 'You have not liked this comment',
                'data' => [
                    'comment_id' => $comment->id,
                    'like_count' => $comment->likes()->count(),
                    'is_liked_by_me' => false,
                ],
            ], 404);
        }

        $like->delete();

        return response()->json([
            'status' => true,
            'message' => 'Comment unliked successfully',
            'data' => [
                'comment_id' => $comment->id,
                'like_count' => $comment->likes()->count(),
                'is_liked_by_me' => false,
            ],
        ], 200);
    }

    // ✅ NAYA METHOD — GET /api/comments/{commentId}/likes
    // Screenshot wala "Likes" list page
    public function index(Request $request, $commentId)
    {
        $comment = PostComment::find($commentId);

        if (!$comment) {
            return response()->json([
                'status' => false,
                'message' => 'Comment not found',
            ], 404);
        }

        $currentUserId = $request->user()?->id;

        $likes = CommentLike::where('comment_id', $comment->id)
            ->with('user:id,first_name,last_name,profile_picture')
            ->latest()
            ->paginate(20);

        $likes->getCollection()->transform(function ($like) use ($currentUserId) {

            $profilePicture = $like->user?->profile_picture;

            $formattedProfilePicture = $profilePicture
                ? (preg_match('/^https?:\/\//i', $profilePicture)
                    ? $profilePicture
                    : asset('storage/' . ltrim($profilePicture, '/')))
                : null;

            return [
                'id'              => $like->id,
                'user_id'         => $like->user_id,
                'name'            => $like->user
                    ? trim(($like->user->first_name ?? '') . ' ' . ($like->user->last_name ?? ''))
                    : 'Unknown User',
                'profile_picture' => $formattedProfilePicture,
                'is_me'           => $currentUserId === $like->user_id,
                'liked_at'        => $like->created_at,
            ];
        });

        return response()->json([
            'status'  => true,
            'message' => 'Comment likes fetched successfully',
            'data'    => $likes,
        ]);
    }
}