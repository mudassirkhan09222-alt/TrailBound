<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\User;
use App\Models\Companion;
use Modules\Trip\Models\Trail;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Carbon\Carbon;

class HomeController extends Controller
{
    /**
     * GET /api/home
     * Home Screen Data
     */
    public function index(Request $request)
    {
        $user = $request->user();

        // 1. CURRENT USER INFO
        $currentUser = [
            'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
            'avatar' => $this->formatUrl($user->profile_picture),
            'greeting' => $this->getGreeting(),
        ];

        // 2. RECOMMENDED FOR YOU (Trails)
        $recommendedTrails = Trail::query()
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn($trail) => [
                'id' => $trail->id,
                'title' => $trail->name,
                'location' => $trail->location ?? 'Unknown Location',
                'image' => null,
                'distance' => round(($trail->distance ?? 0) * 0.621371, 1) . ' miles',
                'difficulty' => $trail->difficulty,
            ])
            ->values();

        // 3. TRIPS HAPPENING NEAR YOU (Trips with Host Info & Capacity)
        $nearbyTrips = Trip::with(['creator', 'media'])
            ->whereDate('start_date', '>=', now())
            ->where('is_completed', false)
            ->withCount(['members as joined_count' => fn($q) => $q->where('status', 'accepted')])
            ->orderBy('start_date', 'asc')
            ->limit(10)
            ->get()
            ->filter(fn($trip) => $trip->joined_count < $trip->group_capacity)
            ->take(5)
            ->map(fn($trip) => [
                'id' => $trip->id,
                'title' => $trip->trail?->name ?? Str::limit($trip->description ?? 'Hiking Trip', 25),
                'description' => Str::limit($trip->description ?? '', 60),
                'host' => [
                    'name' => trim(($trip->creator->first_name ?? '') . ' ' . ($trip->creator->last_name ?? '')),
                    'avatar' => $this->formatUrl($trip->creator->profile_picture ?? null),
                ],
                'image' => $this->formatUrl($trip->media->first()?->url ?? $trip->media_url),
                'dates' => $this->formatDateRange($trip->start_date, $trip->end_date),
                'joined_text' => "{$trip->joined_count}/{$trip->group_capacity} Joined",
                'spots_left' => max(0, $trip->group_capacity - $trip->joined_count),
            ])
            ->values();

        // 4. SUGGESTED COMPANIONS
        $existingConnections = Companion::where('user_id', $user->id)
            ->pluck('companion_id')
            ->merge(Companion::where('companion_id', $user->id)->pluck('user_id'))
            ->push($user->id)
            ->unique()
            ->values()
            ->toArray();

        $companions = User::whereNotIn('id', $existingConnections)
            ->inRandomOrder()
            ->limit(5)
            ->get()
            ->map(fn($u) => [
                'id' => $u->id,
                'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')),
                'avatar' => $this->formatUrl($u->profile_picture),
                'stats' => "Total Hiking's " . ($u->total_hikes ?? 0),
                'can_add' => true,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Home data fetched successfully',
            'data' => [
                'user' => $currentUser,
                'recommended_trails' => $recommendedTrails,
                'nearby_trips' => $nearbyTrips,
                'suggested_companions' => $companions,
            ],
        ]);
    }

    /**
     * GET /api/recommended-trails/see-all
     */
    public function seeAllRecommendedTrails(Request $request)
    {
        $trails = Trail::query()
            ->latest()
            ->get()
            ->map(fn($trail) => [
                'id' => $trail->id,
                'title' => $trail->name,
                'location' => $trail->location ?? 'Unknown Location',
                'image' => null,
                'distance' => round(($trail->distance ?? 0) * 0.621371, 1) . ' miles',
                'difficulty' => $trail->difficulty,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'All recommended trails fetched',
            'data' => $trails,
        ]);
    }

    /**
     * GET /api/nearby-trips/see-all
     */
    public function seeAllNearbyTrips(Request $request)
    {
        $trips = Trip::with(['creator', 'media'])
            ->whereDate('start_date', '>=', now())
            ->where('is_completed', false)
            ->withCount(['members as joined_count' => fn($q) => $q->where('status', 'accepted')])
            ->orderBy('start_date', 'asc')
            ->get()
            ->filter(fn($trip) => $trip->joined_count < $trip->group_capacity)
            ->map(fn($trip) => [
                'id' => $trip->id,
                'title' => $trip->trail?->name ?? Str::limit($trip->description ?? 'Hiking Trip', 25),
                'description' => Str::limit($trip->description ?? '', 60),
                'host' => [
                    'name' => trim(($trip->creator->first_name ?? '') . ' ' . ($trip->creator->last_name ?? '')),
                    'avatar' => $this->formatUrl($trip->creator->profile_picture ?? null),
                ],
                'image' => $this->formatUrl($trip->media->first()?->url ?? $trip->media_url),
                'dates' => $this->formatDateRange($trip->start_date, $trip->end_date),
                'joined_text' => "{$trip->joined_count}/{$trip->group_capacity} Joined",
                'spots_left' => max(0, $trip->group_capacity - $trip->joined_count),
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'All nearby trips fetched',
            'data' => $trips,
        ]);
    }

    // --- Helper Methods ---

    private function getGreeting(): string
    {
        $hour = now()->hour;

        if ($hour >= 5 && $hour < 12) return 'Good Morning';
        if ($hour >= 12 && $hour < 17) return 'Good Afternoon';

        return 'Good Evening';
    }

    private function formatUrl($path)
    {
        if (!$path) return null;

        if (str_starts_with($path, 'http')) return $path;

        return url('/storage/' . ltrim(str_replace('/storage/', '', $path), '/'));
    }

    private function formatDateRange($start, $end)
    {
        $s = Carbon::parse($start);
        $e = Carbon::parse($end);

        if ($s->month === $e->month && $s->year === $e->year) {
            return $s->format('M d') . ' - ' . $e->format('d');
        }

        return $s->format('M d') . ' - ' . $e->format('M d');
    }

    private function calculateDuration($start, $end): string
    {
        $days = Carbon::parse($start)->diffInDays(Carbon::parse($end)) + 1;

        return $days . ' Day' . ($days > 1 ? 's' : '');
    }
}
