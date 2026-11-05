<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\Trail;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ReviewController extends Controller
{
    /**
     * Get all reviews for a specific trail with pagination.
     * GET /api/trails/{id}/reviews
     */
    public function index(Request $request, $trailId)
    {
        $trail = Trail::with(
            'creator:id,first_name,last_name,profile_picture'
        )->findOrFail($trailId);

        $reviews = Review::where('trail_id', $trail->id)
            ->with('user:id,first_name,last_name,profile_picture')
            ->latest()
            ->paginate(10);

        $reviewsData = $reviews->getCollection()
            ->map(function ($review) {
                return [
                    'id' => $review->id,

                    'user' => [
                        'id' => $review->user?->id,

                        'name' => $review->user
                            ? trim(
                                ($review->user->first_name ?? '') . ' ' .
                                ($review->user->last_name ?? '')
                            )
                            : 'Unknown',

                        'avatar' => $this->absoluteUrl(
                            $review->user?->profile_picture
                        ),
                    ],

                    'rating' => $review->rating,
                    'comment' => $review->comment,

                    'created_at' => $review->created_at
                        ? $review->created_at->diffForHumans()
                        : null,

                    'created_at_raw' => $review->created_at,
                ];
            })
            ->values();

        $avgRating = Review::where('trail_id', $trail->id)
            ->avg('rating') ?? 0;

        return response()->json([
            'status' => true,
            'message' => 'Reviews fetched successfully',

            'data' => [
                'trail' => [
                    'id' => $trail->id,
                    'name' => $trail->name,
                    'location' => $trail->location_name,

                    'image' => $this->absoluteUrl(
                        $trail->media_url
                    ),

                    'difficulty' => ucfirst(
                        $trail->difficulty ?? ''
                    ),

                    'creator' => [
                        'name' => $trail->creator
                            ? trim(
                                ($trail->creator->first_name ?? '') . ' ' .
                                ($trail->creator->last_name ?? '')
                            )
                            : 'Unknown',

                        'avatar' => $this->absoluteUrl(
                            $trail->creator?->profile_picture
                        ),
                    ],
                ],

                'average_rating' => round((float) $avgRating, 1),
                'total_reviews' => $reviews->total(),
                'reviews' => $reviewsData,

                'pagination' => [
                    'current_page' => $reviews->currentPage(),
                    'last_page' => $reviews->lastPage(),
                    'per_page' => $reviews->perPage(),
                    'total' => $reviews->total(),
                    'next_page_url' => $reviews->nextPageUrl(),
                    'prev_page_url' => $reviews->previousPageUrl(),
                ],
            ],
        ]);
    }

    /**
     * Store a new trail review and notify its creator.
     * POST /api/trails/{id}/reviews
     */
    public function store(Request $request, $trailId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $trail = Trail::with('creator')->findOrFail($trailId);

        $validator = Validator::make($request->all(), [
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $existingReview = Review::where('user_id', $user->id)
            ->where('trail_id', $trail->id)
            ->first();

        if ($existingReview) {
            return response()->json([
                'status' => false,
                'message' => 'You have already reviewed this trail.',
            ], 409);
        }

        try {
            $review = Review::create([
                'user_id' => $user->id,
                'trail_id' => $trail->id,
                'rating' => $request->rating,
                'comment' => $request->comment,
            ]);

            /*
             * Notify the trail creator.
             * Do not notify the reviewer if they own the trail.
             */
            $creator = $trail->creator;

            if (
                $creator &&
                (int) $creator->id !== (int) $user->id
            ) {
                try {
                    $reviewerName = trim(
                        ($user->first_name ?? '') . ' ' .
                        ($user->last_name ?? '')
                    );

                    if ($reviewerName === '') {
                        $reviewerName = 'A user';
                    }

                    $trailName = $trail->name ?? 'your trail';
                    $comment = trim((string) $review->comment);

                    $message = $reviewerName
                        . ' rated your trail "'
                        . $trailName
                        . '" '
                        . $review->rating
                        . '/5 stars';

                    if ($comment !== '') {
                        $message .= ': ' . $comment;
                    } else {
                        $message .= '.';
                    }

                    app(NotificationService::class)->send(
                        recipient: $creator,
                        data: [
                            'type' => 'trail_review_received',
                            'title' => 'New Trail Review',
                            'message' => $message,
                            'related_id' => $review->id,
                            'related_type' => 'review',
                            'url' => '/trails/' . $trail->id . '/reviews',
                        ],
                        actor: $user
                    );
                } catch (\Throwable $notificationError) {
                    Log::error(
                        'Trail review notification failed.',
                        [
                            'review_id' => $review->id,
                            'trail_id' => $trail->id,
                            'creator_id' => $creator->id,
                            'error' => $notificationError->getMessage(),
                        ]
                    );
                }
            }

            return response()->json([
                'status' => true,
                'message' => 'Review added successfully',

                'data' => [
                    'id' => $review->id,

                    'user' => [
                        'id' => $user->id,

                        'name' => trim(
                            ($user->first_name ?? '') . ' ' .
                            ($user->last_name ?? '')
                        ),

                        'avatar' => $this->absoluteUrl(
                            $user->profile_picture
                        ),
                    ],

                    'rating' => $review->rating,
                    'comment' => $review->comment,

                    'created_at' => $review->created_at
                        ? $review->created_at->diffForHumans()
                        : null,
                ],
            ], 201);

        } catch (\Throwable $e) {
            Log::error('Failed to add trail review.', [
                'trail_id' => $trailId,
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'status' => false,
                'message' => 'Failed to add review',

                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred.',
            ], 500);
        }
    }

    /**
     * Update an existing review.
     * PUT /api/reviews/{id}
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $review = Review::findOrFail($id);

        if ((int) $review->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'rating' => 'sometimes|integer|min:1|max:5',
            'comment' => 'sometimes|nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $review->update($validator->validated());
        $review->refresh();

        return response()->json([
            'status' => true,
            'message' => 'Review updated successfully',

            'data' => [
                'id' => $review->id,
                'rating' => $review->rating,
                'comment' => $review->comment,

                'updated_at' => $review->updated_at
                    ? $review->updated_at->diffForHumans()
                    : null,
            ],
        ]);
    }

    /**
     * Delete a review.
     * DELETE /api/reviews/{id}
     */
    public function destroy(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $review = Review::findOrFail($id);

        if ((int) $review->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $review->delete();

        return response()->json([
            'status' => true,
            'message' => 'Review deleted successfully',
        ]);
    }

    /**
     * Convert relative paths to absolute URLs.
     */
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
}
