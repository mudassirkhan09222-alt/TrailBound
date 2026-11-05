<?php

namespace App\Http\Controllers;

use App\Models\Follow;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;

class FollowController extends Controller
{
    // POST /api/users/{id}/follow
    public function toggle(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $target = User::find($id);

        if (!$target) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        if ((int) $user->id === (int) $target->id) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot follow yourself',
            ], 422);
        }

        $existingFollow = Follow::where('follower_id', $user->id)
            ->where('following_id', $target->id)
            ->first();

        if ($existingFollow) {
            $existingFollow->delete();

            return response()->json([
                'status' => true,
                'message' => 'User unfollowed successfully',
                'is_following' => false,
                'user_id' => $target->id,
            ]);
        }

        Follow::create([
            'follower_id' => $user->id,
            'following_id' => $target->id,
        ]);

        $actorName = trim(
            ($user->first_name ?? '') . ' ' .
            ($user->last_name ?? '')
        );

        app(NotificationService::class)->send(
            $target,
            [
                'type' => 'new_follower',
                'title' => 'New Follower',
                'message' => $actorName . ' started following you.',
                'actor_id' => $user->id,
                'actor_name' => $actorName,
                'actor_avatar' => $user->profile_picture,
                'follower_id' => $user->id,
                'related_id' => $user->id,
                'related_type' => 'user',
                'url' => '/users/' . $user->id,
            ],
            $user
        );

        return response()->json([
            'status' => true,
            'message' => 'User followed successfully',
            'is_following' => true,
            'user_id' => $target->id,
        ], 201);
    }

    // GET /api/users/{id}/followers
    public function followers(Request $request, $id)
    {
        $target = User::find($id);

        if (!$target) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $followers = User::whereIn(
            'id',
            Follow::where('following_id', $target->id)
                ->select('follower_id')
        )
            ->select('id', 'first_name', 'last_name', 'profile_picture')
            ->withCount('followers')
            ->withCount('following')
            ->paginate(20);

        $followers->getCollection()->transform(function ($follower) {
            return $this->formatUser($follower);
        });

        return response()->json([
            'status' => true,
            'message' => 'Followers fetched successfully',
            'data' => $followers,
        ]);
    }

    // GET /api/users/{id}/following
    public function following(Request $request, $id)
    {
        $target = User::find($id);

        if (!$target) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $following = User::whereIn(
            'id',
            Follow::where('follower_id', $target->id)
                ->select('following_id')
        )
            ->select('id', 'first_name', 'last_name', 'profile_picture')
            ->withCount('followers')
            ->withCount('following')
            ->paginate(20);

        $following->getCollection()->transform(function ($followedUser) {
            return $this->formatUser($followedUser);
        });

        return response()->json([
            'status' => true,
            'message' => 'Following list fetched successfully',
            'data' => $following,
        ]);
    }

    // DELETE /api/users/{followerId}/remove-follower
    public function removeFollower(Request $request, $followerId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $follower = User::find($followerId);

        if (!$follower) {
            return response()->json([
                'status' => false,
                'message' => 'Follower not found',
            ], 404);
        }

        $follow = Follow::where('follower_id', $follower->id)
            ->where('following_id', $user->id)
            ->first();

        if (!$follow) {
            return response()->json([
                'status' => false,
                'message' => 'This user is not following you',
            ], 404);
        }

        $follow->delete();

        return response()->json([
            'status' => true,
            'message' => 'Follower removed successfully',
        ]);
    }

    private function formatUser(User $user)
    {
        $avatar = $user->profile_picture;

        if ($avatar && !preg_match('/^https?:\/\//i', $avatar)) {
            $avatar = asset('storage/' . ltrim($avatar, '/'));
        }

        return [
            'id' => $user->id,
            'name' => trim(
                ($user->first_name ?? '') . ' ' .
                ($user->last_name ?? '')
            ),
            'profile_picture' => $avatar,
            'followers_count' => $user->followers_count ?? 0,
            'following_count' => $user->following_count ?? 0,
        ];
    }
}
