<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;

class UserSearchController extends Controller
{
    public function index(Request $request)
    {
        // Force JSON response
        $request->headers->set('Accept', 'application/json');

        // ✅ Agar search query na ho to foran empty response bhej dein
        if (!$request->filled('q') && !$request->filled('filter')) {
            return response()->json([
                'success' => true,
                'data'    => [],
                'pagination' => [
                    'current_page' => 1,
                    'last_page'    => 1,
                    'per_page'     => 10,
                    'total'        => 0,
                ]
            ]);
        }

        $query = User::query();

        // Filter 0: Remove dummy/incomplete users
        $query->whereNotNull('first_name')
              ->where('first_name', '!=', '')
              ->whereNotNull('email');

        // 1. Text Search (First Name OR Last Name OR Bio)
        if ($request->filled('q')) {
            $query->where(function ($q) use ($request) {
                $q->where('first_name', 'LIKE', "%{$request->q}%")
                  ->orWhere('last_name', 'LIKE', "%{$request->q}%")
                  ->orWhere('bio', 'LIKE', "%{$request->q}%");
            });
        }

        // 2. Filter: Needs Partner
        if ($request->filter === 'needs_partner') {
            $query->where('looking_for_partner', true);
        }

        // 3. Filter: High Match
        if ($request->filter === 'high_match') {
            $query->where('total_hikes', '>=', 20)
                  ->orderBy('total_hikes', 'desc');
        } else {
            $query->orderBy('updated_at', 'desc');
        }

        // Paginate results
        $users = $query->paginate(10);

        // Format response with combined full name
        $results = $users->map(function ($user) {
            return [
                'id'                     => $user->id,
                'name'                   => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'avatar_url'             => $user->profile_picture ?? null,
                'total_hikes'            => $user->total_hikes ?? 0,
                'match_percentage'       => $this->calculateMatch($user),
                'is_looking_for_partner' => $user->looking_for_partner ?? false,
            ];
        });

        return response()->json([
            'success' => true,
            'data'    => $results,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'last_page'    => $users->lastPage(),
                'per_page'     => $users->perPage(),
                'total'        => $users->total(),
            ]
        ]);
    }

    private function calculateMatch($user)
    {
        $baseScore = min(100, round(($user->total_hikes / 30) * 100));
        
        if ($user->looking_for_partner) {
            $baseScore += 10;
        }

        return min(100, $baseScore);
    }
}