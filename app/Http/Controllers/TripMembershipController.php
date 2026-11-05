<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TripMembershipController extends Controller
{
    /**
     * POST /api/trips/{id}/join
     * User sends a request to join a trip.
     */
    public function join(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        return DB::transaction(function () use ($id, $user) {
            $trip = Trip::whereKey($id)
                ->lockForUpdate()
                ->firstOrFail();

            // Owner cannot join their own trip.
            if ((int) $trip->user_id === (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'You cannot join your own trip.',
                ], 400);
            }

            // Check whether a membership/request already exists.
            $existingMembership = $trip->members()
                ->where('users.id', $user->id)
                ->exists();

            if ($existingMembership) {
                return response()->json([
                    'status' => false,
                    'message' => 'Join request or membership already exists.',
                ], 400);
            }

            // Count accepted members only. The owner also occupies one spot.
            $acceptedMembersCount = $trip->members()
                ->wherePivot('status', 'accepted')
                ->count();

            $totalJoined = 1 + $acceptedMembersCount;

            $spotsLeft = max(
                0,
                (int) $trip->group_capacity - $totalJoined
            );

            if ($spotsLeft <= 0) {
                return response()->json([
                    'status' => false,
                    'message' => 'This trip is fully booked.',
                ], 400);
            }

            $trip->members()->attach($user->id, [
                'status' => 'pending',
            ]);

            // Notify trip owner about the new join request.
            try {
                $owner = User::find($trip->user_id);

                if ($owner) {
                    app(NotificationService::class)->send(
                        recipient: $owner,
                        data: [
                            'type' => 'trip_join_request',
                            'title' => 'New Trip Join Request',
                            'message' => trim(
                                $user->first_name . ' ' . $user->last_name
                            ) . ' requested to join your trip.',
                            'related_id' => $trip->id,
                            'related_type' => 'trip',
                            'url' => '/trips/' . $trip->id,
                        ],
                        actor: $user
                    );
                }
            } catch (\Throwable $notificationError) {
                report($notificationError);
            }

            return response()->json([
                'status' => true,
                'message' => 'Join request sent. Waiting for host approval.',
                'data' => [
                    'trip_id' => (int) $trip->id,
                    'user_id' => (int) $user->id,
                    'status' => 'pending',
                    'joined_count' => $totalJoined,
                    'spots_left' => $spotsLeft,
                ],
            ], 201);
        });
    }

    /**
     * DELETE /api/trips/{id}/leave
     * A member leaves a trip.
     */
    public function leave(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $trip = Trip::findOrFail($id);

        if ((int) $trip->user_id === (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Host cannot leave their own trip.',
            ], 400);
        }

        $membership = $trip->members()
            ->where('users.id', $user->id)
            ->exists();

        if (!$membership) {
            return response()->json([
                'status' => false,
                'message' => 'You do not have a membership or pending request for this trip.',
            ], 404);
        }

        $trip->members()->detach($user->id);

        return response()->json([
            'status' => true,
            'message' => 'Trip membership or join request cancelled successfully.',
        ]);
    }

    /**
     * PATCH /api/trips/{tripId}/members/{userId}
     * Only the trip owner can accept or reject a request.
     */
    public function updateMemberStatus(
        Request $request,
        $tripId,
        $userId
    ) {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|in:accepted,rejected,pending',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        return DB::transaction(function () use (
            $request,
            $user,
            $tripId,
            $userId
        ) {
            $trip = Trip::whereKey($tripId)
                ->lockForUpdate()
                ->firstOrFail();

            // Only the trip owner can update membership status.
            if ((int) $trip->user_id !== (int) $user->id) {
                return response()->json([
                    'status' => false,
                    'message' => 'Unauthorized action',
                ], 403);
            }

            $membershipExists = $trip->members()
                ->where('users.id', $userId)
                ->exists();

            if (!$membershipExists) {
                return response()->json([
                    'status' => false,
                    'message' => 'Membership request not found.',
                ], 404);
            }

            $newStatus = $request->input('status');

            // Check capacity before accepting a new member.
            if ($newStatus === 'accepted') {
                $currentStatus = DB::table('trip_joins')
                    ->where('trip_id', $trip->id)
                    ->where('user_id', $userId)
                    ->value('status');

                // Only count a member as newly joining if not already accepted.
                if ($currentStatus !== 'accepted') {
                    $acceptedMembersCount = $trip->members()
                        ->wherePivot('status', 'accepted')
                        ->count();

                    // Owner counts as one spot.
                    $totalJoined = 1 + $acceptedMembersCount;

                    if ($totalJoined >= (int) $trip->group_capacity) {
                        return response()->json([
                            'status' => false,
                            'message' => 'Trip has reached maximum capacity.',
                        ], 400);
                    }
                }
            }

            $trip->members()->updateExistingPivot($userId, [
                'status' => $newStatus,
            ]);

            // Notify the member when the owner accepts or rejects the request.
            if (in_array($newStatus, ['accepted', 'rejected'], true)) {
                try {
                    $member = User::find($userId);

                    if ($member) {
                        $isAccepted = $newStatus === 'accepted';

                        app(NotificationService::class)->send(
                            recipient: $member,
                            data: [
                                'type' => $isAccepted
                                    ? 'trip_join_accepted'
                                    : 'trip_join_rejected',
                                'title' => $isAccepted
                                    ? 'Trip Request Accepted'
                                    : 'Trip Request Rejected',
                                'message' => $isAccepted
                                    ? 'Your request to join the trip has been accepted.'
                                    : 'Your request to join the trip has been rejected.',
                                'related_id' => $trip->id,
                                'related_type' => 'trip',
                                'url' => '/trips/' . $trip->id,
                            ],
                            actor: $user
                        );
                    }
                } catch (\Throwable $notificationError) {
                    report($notificationError);
                }
            }

            $acceptedMembersCount = $trip->members()
                ->wherePivot('status', 'accepted')
                ->count();

            $totalJoined = 1 + $acceptedMembersCount;

            $spotsLeft = max(
                0,
                (int) $trip->group_capacity - $totalJoined
            );

            return response()->json([
                'status' => true,
                'message' => 'Member status updated successfully.',
                'data' => [
                    'trip_id' => (int) $trip->id,
                    'user_id' => (int) $userId,
                    'status' => $newStatus,
                    'joined_count' => $totalJoined,
                    'joined_text' => "{$totalJoined}/{$trip->group_capacity} Joined",
                    'spots_left' => $spotsLeft,
                    'is_full' => $spotsLeft <= 0,
                ],
            ]);
        });
    }
}
