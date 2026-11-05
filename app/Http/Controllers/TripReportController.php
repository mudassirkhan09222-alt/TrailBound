<?php

namespace App\Http\Controllers;

use App\Models\TripReport;
use App\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TripReportController extends Controller
{
    /**
     * GET /api/trips/{id}/reports?page=1
     */
    public function index($tripId)
    {
        $trip = Trip::with(
            'creator:id,first_name,last_name,profile_picture'
        )->findOrFail($tripId);

        // Pagination: 10 reports per page
        $reports = TripReport::where('trip_id', $trip->id)
            ->with('user:id,first_name,last_name,profile_picture')
            ->latest()
            ->paginate(10);

        $reportsData = $reports->getCollection()
            ->map(function ($report) {
                return [
                    'id' => $report->id,
                    'user' => [
                        'id' => $report->user?->id,
                        'name' => $report->user
                            ? trim(
                                $report->user->first_name . ' ' .
                                $report->user->last_name
                            )
                            : 'Unknown',
                        'avatar' => $this->absoluteUrl(
                            $report->user?->profile_picture
                        ),
                    ],
                    'condition' => $report->condition,
                    'description' => $report->description,
                    'issues' => $report->issues ?? [],
                    'confirmations' => $report->confirmations,
                    'created_at' => $report->created_at
                        ? $report->created_at->diffForHumans()
                        : null,
                    'created_at_raw' => $report->created_at,
                ];
            })
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Trip reports fetched successfully',
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

                'reports' => $reportsData,

                'pagination' => [
                    'current_page' => $reports->currentPage(),
                    'last_page' => $reports->lastPage(),
                    'per_page' => $reports->perPage(),
                    'total' => $reports->total(),
                    'from' => $reports->firstItem(),
                    'to' => $reports->lastItem(),
                    'next_page_url' => $reports->nextPageUrl(),
                    'prev_page_url' => $reports->previousPageUrl(),
                    'has_more_pages' => $reports->hasMorePages(),
                ],
            ],
        ]);
    }

    /**
     * POST /api/trips/{id}/reports
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
            'condition' => 'required|string',
            'description' => 'nullable|string|max:1000',
            'issues' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            Trip::findOrFail($tripId);

            $report = TripReport::create([
                'user_id' => $user->id,
                'trip_id' => $tripId,
                'condition' => $request->condition,
                'description' => $request->description,
                'issues' => $request->issues,
                'confirmations' => 0,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Report submitted successfully',
                'data' => [
                    'id' => $report->id,
                    'condition' => $report->condition,
                    'description' => $report->description,
                    'issues' => $report->issues,
                    'confirmations' => $report->confirmations,
                    'created_at' => 'just now',
                ],
            ], 201);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => false,
                'message' => 'Failed to submit report',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * PUT /api/trip-reports/{id}
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

        $report = TripReport::findOrFail($id);

        if ((int) $report->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'condition' => 'sometimes|string',
            'description' => 'sometimes|nullable|string|max:1000',
            'issues' => 'sometimes|nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $report->update($validator->validated());
        $report->refresh();

        return response()->json([
            'status' => true,
            'message' => 'Report updated successfully',
            'data' => [
                'id' => $report->id,
                'condition' => $report->condition,
                'description' => $report->description,
                'issues' => $report->issues,
                'updated_at' => $report->updated_at
                    ? $report->updated_at->diffForHumans()
                    : null,
            ],
        ]);
    }

    /**
     * DELETE /api/trip-reports/{id}
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

        $report = TripReport::findOrFail($id);

        if ((int) $report->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $report->delete();

        return response()->json([
            'status' => true,
            'message' => 'Report deleted successfully',
        ]);
    }

    /**
     * POST /api/trip-reports/{id}/confirm
     */
    public function confirm($reportId)
    {
        $user = request()->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $report = TripReport::findOrFail($reportId);
        $report->increment('confirmations');

        return response()->json([
            'status' => true,
            'message' => 'Report confirmed',
            'data' => [
                'id' => $report->id,
                'confirmations' => $report->confirmations,
            ],
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
