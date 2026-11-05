<?php

namespace App\Http\Controllers;

use App\Models\Post;
use App\Models\PostReport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PostReportController extends Controller
{
    /**
     * POST /api/posts/{postId}/report
     * Report a post
     */
    public function store(Request $request, $postId)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $post = Post::find($postId);

        if (!$post) {
            return response()->json([
                'status' => false,
                'message' => 'Post not found'
            ], 404);
        }

        // User apni post report nahi kar sakta
        if ((int) $post->user_id === (int) $user->id) {
            return response()->json([
                'status' => false,
                'message' => 'You cannot report your own post'
            ], 422);
        }

        $validator = Validator::make($request->all(), [
            'reason' => [
                'required',
                'string',
                'in:spam,harassment,inappropriate_content,false_information,other'
            ],
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        // Duplicate report check
        $alreadyReported = PostReport::where('post_id', $post->id)
            ->where('reported_by', $user->id)
            ->exists();

        if ($alreadyReported) {
            return response()->json([
                'status' => false,
                'message' => 'You have already reported this post'
            ], 409);
        }

        $report = PostReport::create([
            'post_id' => $post->id,
            'reported_by' => $user->id,
            'reason' => $request->reason,
            'description' => $request->description,
            'status' => 'pending',
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Post reported successfully',
            'data' => [
                'id' => $report->id,
                'post_id' => $report->post_id,
                'reported_by' => $report->reported_by,
                'reason' => $report->reason,
                'description' => $report->description,
                'status' => $report->status,
                'created_at' => $report->created_at,
            ]
        ], 201);
    }

    /**
     * GET /api/my-reports
     * Get reports submitted by the authenticated user
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated'
            ], 401);
        }

        $reports = PostReport::with([
            'post:id,title,category'
        ])
        ->where('reported_by', $user->id)
        ->latest()
        ->paginate(20);

        return response()->json([
            'status' => true,
            'message' => 'Your reports fetched successfully',
            'data' => $reports->items(),
            'pagination' => [
                'current_page' => $reports->currentPage(),
                'per_page' => $reports->perPage(),
                'total' => $reports->total(),
                'last_page' => $reports->lastPage(),
            ]
        ], 200);
    }
}
