<?php

namespace App\Http\Controllers;

use App\Models\Hiker;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;

class HikerController extends Controller
{
    public function show(int $id): JsonResponse
    {
        // 1. Pehle data fetch karein bina groupBy ke (taake SQL error na aaye)
        $hiker = Hiker::with(['recentTrips' => function ($query) {
            $query->limit(10)->orderByDesc('start_date'); // Thora zyada limit lein taake unique mil sakein
        }])->findOrFail($id);

        // 2. PHP Collection par duplicates hatayein (uniqueBy title ya id)
        $uniqueTrips = $hiker->recentTrips->unique('id')->take(5)->values();

        return response()->json([
            'id'         => $hiker->id,
            'name'       => $hiker->name,
            'avatar_url' => $hiker->avatar ? asset('storage/' . $hiker->avatar) : null,
            'bio'        => $hiker->bio,
            'tags'       => $hiker->tags ?? [],
            
            'stats'      => [
                'trails'  => $hiker->trails_completed,
                'miles'   => number_format($hiker->total_miles, 2, '.', ''), 
                'reviews' => $hiker->reviews_count,
            ],

            'recent_trips' => $uniqueTrips->map(fn ($trip) => [
                'title'     => $trip->title,
                'image_url' => $trip->media_url ? asset('storage/' . $trip->media_url) : null,
                'dates'     => optional($trip->start_date)->format('M d') . ' - ' . optional($trip->end_date)->format('d'),
                'capacity'  => ($trip->joined_count ?? 0) . '/' . ($trip->group_capacity ?? 0) . ' Joined',
                'status'    => $trip->is_completed ? 'Completed' : 'Open',
            ]),
        ]);
    }
}