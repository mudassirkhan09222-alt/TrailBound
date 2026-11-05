<?php

namespace App\Http\Controllers;

use App\Models\TripReview;
use App\Models\Trip;
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TripReviewController extends Controller
{
    /**
     * GET /api/trips/{id}/reviews?page=1
     */
    public function index($tripId)
    {
        $trip = Trip::with(
            'creator:id,first_name,last_name,profile_picture'
        )->findOrFail($tripId);

        // Pagination: 10 reviews per page
        $reviews = TripReview::where('trip_id', $trip->id)
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
                                $review->user->first_name . ' ' .
                                $review->user->last_name
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

        $avgRating = TripReview::where(
            'trip_id',
            $trip->id
        )->avg('rating') ?? 0;

        return response()->json([
            'status' => true,
            'message' => 'Trip reviews fetched successfully',
            'data' => [
                'trip' => [
                    'id' => $trip->id,
                    'title' => $trip->title,
                    'location' => $trip->location_name,
                    'image' => $this->absoluteUrl(
                        $trip->media_url
                    ),
                    'start_date' => $trip->start_date,
                    'creator' => [
                        'name' => $trip->creator
                            ? trim(
                                $trip->creator->first_name . ' ' .
                                $trip->creator->last_name
                            )
                            : 'Unknown',
                        'avatar' => $this->absoluteUrl(
                            $trip->creator?->profile_picture
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
                    'from' => $reviews->firstItem(),
                    'to' => $reviews->lastItem(),
                    'next_page_url' => $reviews->nextPageUrl(),
                    'prev_page_url' => $reviews->previousPageUrl(),
                    'has_more_pages' => $reviews->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/trips/{id}/reviews
     */
    public function store(Request $request, $tripId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

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

        // Load trip and creator for notification
        $trip = Trip::with('creator')->findOrFail($tripId);

        $existingReview = TripReview::where(
            'user_id',
            $user->id
        )->where('trip_id', $tripId)->first();

        if ($existingReview) {
            return response()->json([
                'status' => false,
                'message' => 'You have already reviewed this trip.',
            ], 409);
        }

        try {
            $review = TripReview::create([
                'user_id' => $user->id,
                'trip_id' => $tripId,
                'rating' => $request->rating,
                'comment' => $request->comment,
            ]);

            // Notify trip creator about the new review
            try {
                if ($trip->creator) {
                    app(NotificationService::class)->send(
                        recipient: $trip->creator,
                        data: [
                            'type' => 'trip_review_created',
                            'title' => 'New Trip Review',
                            'message' => trim(
                                $user->first_name . ' ' .
                                $user->last_name
                            ) . ' reviewed your trip: '
                                . ($trip->location_name ?: 'your trip'),
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
                'message' => 'Review added successfully',
                'data' => [
                    'id' => $review->id,
                    'user' => [
                        'name' => trim(
                            $user->first_name . ' ' . $user->last_name
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
            return response()->json([
                'status' => false,
                'message' => 'Failed to add review',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/trip-reviews/{id}
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

        $review = TripReview::findOrFail($id);

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

        // Notify trip creator that a review has been updated
        try {
            $trip = Trip::with('creator')->find($review->trip_id);

            if ($trip && $trip->creator) {
                app(NotificationService::class)->send(
                    recipient: $trip->creator,
                    data: [
                        'type' => 'trip_review_updated',
                        'title' => 'Trip Review Updated',
                        'message' => trim(
                            $user->first_name . ' ' .
                            $user->last_name
                        ) . ' updated their review for your trip: '
                            . ($trip->location_name ?: 'your trip'),
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
     * DELETE /api/trip-reviews/{id}
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

        $review = TripReview::findOrFail($id);

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
