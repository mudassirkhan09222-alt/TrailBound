<?php

namespace App\Http\Controllers;

use App\Models\Companion;
use App\Models\User;
use Illuminate\Http\Request;

class CompanionController extends Controller
{
    /**
     * Get Suggested Companions (For Home Screen)
     * GET /api/companions/suggested
     */
    public function suggested(Request $request)
    {
        $currentUser = $request->user();
        
        // Exclude users who are already connected, pending, or current user
        $existingConnections = Companion::where('user_id', $currentUser->id)
            ->pluck('companion_id')
            ->merge(
                Companion::where('companion_id', $currentUser->id)->pluck('user_id')
            )
            ->push($currentUser->id)
            ->toArray();

        $companions = User::whereNotIn('id', $existingConnections)
            ->inRandomOrder()
            ->limit(5)
            ->get()
            ->map(function ($u) {
                return [
                    'id' => $u->id,
                    'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                    'avatar' => $this->formatUrl($u->profile_picture),
                    'stats' => "Total Hiking's " . ($u->total_hikes ?? 0),
                    'can_add' => true,
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Suggested companions fetched successfully',
            'data' => $companions
        ]);
    }

    /**
     * Send Companion Request (Add Button Click)
     * POST /api/companions/request/{userId}
     */
    public function sendRequest(Request $request, $userId)
    {
        $currentUser = $request->user();
        
        if ($currentUser->id == $userId) {
            return response()->json(['status' => false, 'message' => 'Cannot add yourself as companion'], 400);
        }

        $exists = Companion::where(function($q) use ($currentUser, $userId) {
            $q->where('user_id', $currentUser->id)->where('companion_id', $userId);
        })->orWhere(function($q) use ($currentUser, $userId) {
            $q->where('user_id', $userId)->where('companion_id', $currentUser->id);
        })->first();

        if ($exists) {
            return response()->json(['status' => false, 'message' => 'Request already sent or already companions'], 409);
        }

        Companion::create([
            'user_id' => $currentUser->id,
            'companion_id' => $userId,
            'status' => 'pending'
        ]);

        return response()->json(['status' => true, 'message' => 'Companion request sent successfully']);
    }

    /**
     * Accept Companion Request
     * POST /api/companions/accept/{requestId}
     */
    public function acceptRequest(Request $request, $requestId)
    {
        $companion = Companion::where('companion_id', $request->user()->id)
            ->where('id', $requestId)
            ->where('status', 'pending')
            ->firstOrFail();

        $companion->update(['status' => 'accepted']);

        return response()->json(['status' => true, 'message' => 'Companion request accepted']);
    }

    /**
     * Reject/Remove Companion
     * DELETE /api/companions/{requestId}
     */
    public function destroy(Request $request, $requestId)
    {
        $companion = Companion::where(function($q) use ($request) {
            $q->where('user_id', $request->user()->id)
              ->orWhere('companion_id', $request->user()->id);
        })->where('id', $requestId)->firstOrFail();

        $companion->delete();

        return response()->json(['status' => true, 'message' => 'Companion removed successfully']);
    }

    /**
     * Get My Companions List (Accepted Only)
     * GET /api/companions
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $companions = Companion::where(function($q) use ($user) {
            $q->where('user_id', $user->id)->orWhere('companion_id', $user->id);
        })
        ->where('status', 'accepted')
        ->with(['sender:id,first_name,last_name,profile_picture,total_hikes', 'receiver:id,first_name,last_name,profile_picture,total_hikes'])
        ->get()
        ->map(function($c) use ($user) {
            $companionUser = $c->user_id == $user->id ? $c->receiver : $c->sender;
            
            return [
                'id' => $companionUser->id,
                'name' => trim(($companionUser->first_name ?? '') . ' ' . ($companionUser->last_name ?? '')),
                'avatar' => $this->formatUrl($companionUser->profile_picture),
                'stats' => "Total Hiking's " . ($companionUser->total_hikes ?? 0),
            ];
        });

        return response()->json([
            'status' => true,
            'message' => 'My companions fetched successfully',
            'data' => $companions
        ]);
    }

    private function formatUrl($path)
    {
        if (!$path) return null;
        if (str_starts_with($path, 'http')) return $path;
        return url('/storage/' . ltrim(str_replace('/storage/', '', $path), '/'));
    }
}