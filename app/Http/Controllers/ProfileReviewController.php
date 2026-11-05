<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\ProfileReview;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;

class ProfileReviewController extends Controller
{
    private function absoluteUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        if (
            str_starts_with($url, 'http://') ||
            str_starts_with($url, 'https://')
        ) {
            return $url;
        }

        return url('/' . ltrim($url, '/'));
    }

    /**
     * GET /api/users/{id}/profile-reviews
     */
    public function index($id): JsonResponse
    {
        $user = User::find($id);

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $reviews = $user->receivedProfileReviews()
            ->with('reviewer')
            ->latest()
            ->paginate(10);

        $averageRating = $user->receivedProfileReviews()
            ->avg('rating');

        return response()->json([
            'status' => true,
            'data' => [
                'profile' => [
                    'id' => $user->id,

                    'name' => trim(
                        ($user->first_name ?? '') . ' ' .
                        ($user->last_name ?? '')
                    ) ?: ($user->email ?? ''),

                    'avatar_url' => $this->absoluteUrl(
                        $user->profile_picture
                    ),

                    'bio' => $user->bio,

                    'tags' => array_merge(
                        $user->tags ?? [],
                        $user->looking_for_partner
                            ? ['Looking for Partner']
                            : []
                    ),

                    'stats' => [
                        'trails' => $user->total_hikes ?? 0,

                        'miles' => number_format(
                            $user->total_miles ?? 0,
                            2,
                            '.',
                            ''
                        ),

                        'reviews' => $user->reviews_count ?? 0,
                    ],

                    'average_rating' => round(
                        $averageRating ?? 0,
                        1
                    ),
                ],

                'reviews' => [
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'from' => $reviews->firstItem(),
                    'to' => $reviews->lastItem(),
                    'next_page_url' => $reviews->nextPageUrl(),
                    'prev_page_url' => $reviews->previousPageUrl(),
                    'has_more_pages' => $reviews->hasMorePages(),

                    'data' => $reviews->getCollection()->map(
                        fn ($r) => [
                            'id' => $r->id,
                            'rating' => $r->rating,
                            'comment' => $r->comment,

                            'created_at' => $r->created_at
                                ? $r->created_at->diffForHumans()
                                : null,

                            'reviewer' => $r->reviewer ? [
                                'id' => $r->reviewer->id,

                                'name' => trim(
                                    ($r->reviewer->first_name ?? '') . ' ' .
                                    ($r->reviewer->last_name ?? '')
                                ),

                                'avatar' => $this->absoluteUrl(
                                    $r->reviewer->profile_picture
                                ),
                            ] : null,
                        ]
                    )->values(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/profile-reviews
     * Submit a profile review and notify the profile owner.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reviewee_id' => 'required|exists:users,id',
            'rating' => 'required|numeric|min:1|max:5',
            'comment' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $currentUser = $request->user();

        if (!$currentUser) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $targetUser = User::find($request->reviewee_id);

        if (!$targetUser) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        // Prevent self-review
        if ((int) $currentUser->id === (int) $targetUser->id) {
            return response()->json([
                'status' => false,
                'message' => 'Cannot review your own profile',
            ], 403);
        }

        // Prevent duplicate review
        if ($currentUser->hasReviewedProfile($targetUser)) {
            return response()->json([
                'status' => false,
                'message' => 'You have already reviewed this profile',
            ], 409);
        }

        $review = ProfileReview::create([
            'reviewer_id' => $currentUser->id,
            'reviewee_id' => $targetUser->id,
            'rating' => $request->rating,
            'comment' => $request->comment,
        ]);

        // Update review count
        $targetUser->increment('reviews_count');

        // Notify the profile owner.
        // Notification failure must not fail the review submission.
        try {
            $reviewerName = trim(
                ($currentUser->first_name ?? '') . ' ' .
                ($currentUser->last_name ?? '')
            );

            if ($reviewerName === '') {
                $reviewerName = 'A user';
            }

            app(NotificationService::class)->send(
                recipient: $targetUser,
                data: [
                    'type' => 'profile_review_received',
                    'title' => 'New Profile Review',
                    'message' => $reviewerName
                        . ' reviewed your profile with '
                        . $review->rating
                        . '/5 stars: '
                        . $review->comment,
                    'related_id' => $review->id,
                    'related_type' => 'profile_review',
                    'url' => '/users/' . $targetUser->id
                        . '/profile-reviews',
                ],
                actor: $currentUser
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error(
                'Profile review notification failed.',
                [
                    'review_id' => $review->id,
                    'reviewee_id' => $targetUser->id,
                    'error' => $e->getMessage(),
                ]
            );
        }

        return response()->json([
            'status' => true,
            'message' => 'Profile review submitted successfully',
            'data' => $review,
        ], 201);
    }

    /**
     * PUT /api/profile-reviews/{id}
     * Edit only your own review.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $review = ProfileReview::findOrFail($id);

        if (
            (int) $review->reviewer_id !==
            (int) $request->user()->id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'sometimes|numeric|min:1|max:5',
            'comment' => 'sometimes|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'errors' => $validator->errors(),
            ], 422);
        }

        $review->update($validator->validated());

        return response()->json([
            'status' => true,
            'message' => 'Review updated',
            'data' => $review->fresh(),
        ]);
    }

    /**
     * DELETE /api/profile-reviews/{id}
     * Delete only your own review.
     */
    public function destroy(Request $request, $id): JsonResponse
    {
        $review = ProfileReview::findOrFail($id);

        if (
            (int) $review->reviewer_id !==
            (int) $request->user()->id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized',
            ], 403);
        }

        $reviewee = $review->reviewee;

        $review->delete();

        if ($reviewee && $reviewee->reviews_count > 0) {
            $reviewee->decrement('reviews_count');
        }

        return response()->json([
            'status' => true,
            'message' => 'Profile review deleted',
        ]);
    }
}
