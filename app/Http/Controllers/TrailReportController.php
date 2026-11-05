<?php

namespace App\Http\Controllers;

use App\Models\TrailReport;
use App\Models\Trail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class TrailReportController extends Controller
{
    /**
     * Get All Reports for a Specific Trail WITH Trail Details.
     * GET /api/trails/{id}/reports?page=1
     */
    public function index($trailId)
    {
        $trail = Trail::findOrFail($trailId);

        // Pagination: 10 reports per page
        $reports = TrailReport::where('trail_id', $trail->id)
            ->with('user:id,first_name,last_name,profile_picture')
            ->latest()
            ->paginate(10);

        // Format only the reports of the current page
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
                    'facilities' => $report->facilities ?? [],
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
            'message' => 'Reports fetched successfully',
            'data' => [
                'trail' => [
                    'id' => $trail->id,
                    'name' => $trail->name,
                    'location' => $trail->location_name,
                    'image' => $this->absoluteUrl($trail->media_url),
                    'description' => $trail->description,
                    'difficulty' => ucfirst($trail->difficulty),
                ],

                'reports' => $reportsData,

                // Pagination information
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
     * Store a New Report.
     * POST /api/trails/{id}/reports
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

        $validator = Validator::make($request->all(), [
            'condition' => 'required|string|in:Clear,Muddy,Caution,Snow,Closed',
            'description' => 'nullable|string|max:1000',
            'facilities' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        try {
            // Ensure the trail exists
            Trail::findOrFail($trailId);

            $report = TrailReport::create([
                'user_id' => $user->id,
                'trail_id' => $trailId,
                'condition' => $request->condition,
                'description' => $request->description,
                'facilities' => $request->facilities,
                'confirmations' => 0,
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Report submitted successfully',
                'data' => [
                    'id' => $report->id,
                    'condition' => $report->condition,
                    'description' => $report->description,
                    'facilities' => $report->facilities,
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
     * Update an Existing Report.
     * PUT /api/reports/{id}
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

        $report = TrailReport::findOrFail($id);

        // Only the owner can update their report
        if ((int) $report->user_id !== (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'condition' => 'sometimes|string|in:Clear,Muddy,Caution,Snow,Closed',
            'description' => 'sometimes|nullable|string|max:1000',
            'facilities' => 'sometimes|nullable|array',
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
                'facilities' => $report->facilities,
                'updated_at' => $report->updated_at
                    ? $report->updated_at->diffForHumans()
                    : null,
            ],
        ]);
    }

    /**
     * Delete a Report.
     * DELETE /api/reports/{id}
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

        $report = TrailReport::findOrFail($id);

        // Only the owner can delete their report
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
     * Confirm a Report.
     * POST /api/reports/{id}/confirm
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

        $report = TrailReport::findOrFail($reportId);
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

    /**
     * Convert a storage path into an absolute URL.
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
