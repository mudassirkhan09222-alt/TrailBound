<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\UserReport;
use App\Models\BlockedUser;
use App\Models\Follow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class UserActionController extends Controller
{
    /**
     * Report User
     * POST /api/users/{id}/report
     */
    public function report(Request $request, $id)
    {
        // Get authenticated user
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login first.'
            ], 401);
        }

        $authUserId = $authUser->id;

        // Check reported user exists
        $reportedUser = User::find($id);

        if (!$reportedUser) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Prevent reporting yourself
        if ((int) $authUserId === (int) $id) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot report yourself'
            ], 422);
        }

        // Allowed report reasons
        $validReasons = [
            'Harassment or bullying',
            'Fake account or impersonation',
            'Spam or scams',
            'Inappropriate behavior',
            'Hate speech',
            'Other'
        ];

        // Validate request
        $validator = Validator::make($request->all(), [
            'reason' => 'required|in:' . implode(',', $validReasons),
            'details' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Prevent duplicate reports for the same reason
        $existingReport = UserReport::where(
                'reporter_id',
                $authUserId
            )
            ->where('reported_id', $id)
            ->where('reason', $request->reason)
            ->first();

        if ($existingReport) {
            return response()->json([
                'status' => false,
                'message' => 'You have already reported this user for this reason'
            ], 422);
        }

        // Create report
        $report = UserReport::create([
            'reporter_id' => $authUserId,
            'reported_id' => $reportedUser->id,
            'reason' => $request->reason,
            'details' => $request->details,
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'User reported successfully. Our team will review it.',
            'data' => [
                'id' => $report->id,
                'reporter_id' => $report->reporter_id,
                'reported_user_id' => $report->reported_id,
                'reason' => $report->reason,
                'details' => $report->details,
                'status' => $report->status,
            ]
        ], 201);
    }

    /**
     * Block / Unblock User (Toggle)
     * POST /api/users/{id}/block
     */
    public function toggleBlock(Request $request, $id)
    {
        // Get authenticated user
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login first.'
            ], 401);
        }

        $authUserId = $authUser->id;

        // Check target user exists
        $targetUser = User::find($id);

        if (!$targetUser) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Prevent blocking yourself
        if ((int) $authUserId === (int) $id) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot block yourself'
            ], 422);
        }

        // Check optional reason
        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Check existing block
        $existingBlock = BlockedUser::where(
                'blocker_id',
                $authUserId
            )
            ->where('blocked_id', $targetUser->id)
            ->first();

        if ($existingBlock) {
            // Unblock user
            $existingBlock->delete();

            return response()->json([
                'status' => true,
                'action' => 'unblocked',
                'message' => 'User unblocked successfully',
                'data' => [
                    'user_id' => $targetUser->id,
                    'is_blocked' => false
                ]
            ], 200);
        }

        // Block user
        BlockedUser::create([
            'blocker_id' => $authUserId,
            'blocked_id' => $targetUser->id,
            'reason' => $request->reason,
        ]);

        // Remove follow relationships in both directions
        Follow::where('follower_id', $authUserId)
            ->where('following_id', $targetUser->id)
            ->delete();

        Follow::where('follower_id', $targetUser->id)
            ->where('following_id', $authUserId)
            ->delete();

        return response()->json([
            'status' => true,
            'action' => 'blocked',
            'message' => 'User blocked successfully',
            'data' => [
                'user_id' => $targetUser->id,
                'is_blocked' => true
            ]
        ], 201);
    }

    /**
     * Check Block Status
     * GET /api/users/{id}/block-status
     */
    public function blockStatus(Request $request, $id)
    {
        // Get authenticated user
        $authUser = $request->user();

        if (!$authUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated. Please login first.'
            ], 401);
        }

        // Check target user exists
        $targetUser = User::find($id);

        if (!$targetUser) {
            return response()->json([
                'status' => false,
                'message' => 'User not found'
            ], 404);
        }

        // Check whether current user has blocked target user
        $isBlocked = BlockedUser::where(
                'blocker_id',
                $authUser->id
            )
            ->where('blocked_id', $targetUser->id)
            ->exists();

        return response()->json([
            'status' => true,
            'message' => 'Block status fetched successfully',
            'data' => [
                'user_id' => $targetUser->id,
                'is_blocked' => $isBlocked
            ]
        ], 200);
    }
}
