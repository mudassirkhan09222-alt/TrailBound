<?php

namespace App\Http\Controllers;

use App\Models\Trail;
use App\Models\TrailMedia;
use App\Models\Trip;
use App\Models\User;
use App\Models\TrailLike; // Added for Likes
use App\Services\NotificationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth; // Added for Auth

class TrailController extends Controller
{
    /**
     * GET /api/trails
     */
    public function index(Request $request)
    {
        $userId = Auth::id();
        $query = Trail::query();

        if ($request->has('search')) {
            $search = $request->input('search');

            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('location_name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->has('difficulty')) {
            $difficulties = $request->input('difficulty');

            if (is_array($difficulties)) {
                $query->whereIn('difficulty', $difficulties);
            } else {
                $query->where('difficulty', $difficulties);
            }
        }

        if ($request->has('peak_month')) {
            $query->where(
                'peak_month',
                'like',
                '%' . $request->input('peak_month') . '%'
            );
        }

        if ($request->has('rating_min')) {
            $query->whereHas('reviews', function ($q) use ($request) {
                $q->selectRaw('AVG(rating) as avg_rating')
                    ->groupBy('trail_id')
                    ->havingRaw(
                        'AVG(rating) >= ?',
                        [$request->input('rating_min')]
                    );
            });
        }

        if ($request->has('rating_max')) {
            $query->whereHas('reviews', function ($q) use ($request) {
                $q->selectRaw('AVG(rating) as avg_rating')
                    ->groupBy('trail_id')
                    ->havingRaw(
                        'AVG(rating) <= ?',
                        [$request->input('rating_max')]
                    );
            });
        }

        if ($request->has('distance_min')) {
            $query->where('distance', '>=', $request->input('distance_min'));
        }

        if ($request->has('distance_max')) {
            $query->where('distance', '<=', $request->input('distance_max'));
        }

        if ($request->has('elevation_min')) {
            $query->where('elevation', '>=', $request->input('elevation_min'));
        }

        if ($request->has('elevation_max')) {
            $query->where('elevation', '<=', $request->input('elevation_max'));
        }

        if (
            $request->has('lat') &&
            $request->has('long') &&
            $request->has('radius')
        ) {
            $lat = $request->input('lat');
            $lng = $request->input('long');
            $radius = $request->input('radius');

            $query->selectRaw(
                '*, (6371 * acos(
                    cos(radians(?)) *
                    cos(radians(latitude)) *
                    cos(radians(longitude) - radians(?)) +
                    sin(radians(?)) *
                    sin(radians(latitude))
                )) AS distance_from_user',
                [$lat, $lng, $lat]
            )
                ->having('distance_from_user', '<=', $radius)
                ->orderBy('distance_from_user');
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Add likes count to the query
        $trails = $query->with([
            'creator:id,first_name,last_name,profile_picture',
            'media',
        ])
        ->withCount('likes as likes_count')
        ->paginate(15);

        // Get liked trail IDs for current user
        $likedTrailIds = [];
        if ($userId) {
            $likedTrailIds = TrailLike::where('user_id', $userId)
                ->whereIn('trail_id', $trails->pluck('id'))
                ->pluck('trail_id')
                ->toArray();
        }

        return response()->json([
            'status' => true,
            'message' => 'Trails fetched successfully',
            'data' => $trails->getCollection()
                ->map(fn ($trail) => array_merge($this->formatTrail($trail), [
                    'likes_count' => $trail->likes_count,
                    'is_liked' => in_array($trail->id, $likedTrailIds),
                ]))
                ->values(),
            'pagination' => [
                'current_page' => $trails->currentPage(),
                'last_page' => $trails->lastPage(),
                'total' => $trails->total(),
            ],
        ]);
    }

    /**
     * GET /api/trails/user/{userId}
     */
    public function userTrails($userId)
    {
        $currentUserId = Auth::id();

        if (!User::where('id', $userId)->exists()) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $trails = Trail::where('user_id', $userId)
            ->with([
                'creator:id,first_name,last_name,profile_picture',
                'media',
            ])
            ->withCount('likes as likes_count')
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        // Get liked trail IDs for current viewer
        $likedTrailIds = [];
        if ($currentUserId) {
            $likedTrailIds = TrailLike::where('user_id', $currentUserId)
                ->whereIn('trail_id', $trails->pluck('id'))
                ->pluck('trail_id')
                ->toArray();
        }

        return response()->json([
            'status' => true,
            'message' => 'User trails fetched successfully',
            'user_id' => (int) $userId,
            'data' => $trails->getCollection()
                ->map(fn ($trail) => array_merge($this->formatTrail($trail), [
                    'likes_count' => $trail->likes_count,
                    'is_liked' => in_array($trail->id, $likedTrailIds),
                ]))
                ->values(),
            'pagination' => [
                'current_page' => $trails->currentPage(),
                'last_page' => $trails->lastPage(),
                'per_page' => $trails->perPage(),
                'total' => $trails->total(),
            ],
        ]);
    }

    /**
     * POST /api/trails
     * Create a trail and upload media.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'location_name' => 'required|string|max:255',
            'difficulty' => 'required|in:easy,moderate,hard,Easy,Moderate,Hard',
            'peak_month' => 'nullable|string|max:100',
            'description' => 'nullable|string',
            'track_points' => 'required|json',
            'elevation' => 'nullable|numeric',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp,mp4,mov|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        $paths = [];
        $coverPath = null;

        try {
            DB::beginTransaction();

            $points = json_decode($request->track_points, true);

            if (!is_array($points) || count($points) < 2) {
                throw new \RuntimeException(
                    'At least two track points are required to calculate distance.'
                );
            }

            $calculatedDistance = $this->calculateTotalDistance($points);

            $startLat = $points[0]['lat'] ?? null;
            $startLng = $points[0]['lng'] ?? null;

            if ($request->hasFile('image')) {
                $coverPath = $request->file('image')
                    ->store('trails/covers', 'public');

                if (!$coverPath) {
                    throw new \RuntimeException('Cover image upload failed.');
                }
            }

            $trail = Trail::create([
                'user_id' => $user->id,
                'name' => $request->name,
                'location_name' => $request->location_name,
                'latitude' => $startLat,
                'longitude' => $startLng,
                'distance' => $calculatedDistance,
                'elevation' => $request->input('elevation', 0),
                'difficulty' => strtolower($request->difficulty),
                'peak_month' => $request->peak_month,
                'description' => $request->description,
                'media_url' => $coverPath
                    ? Storage::url($coverPath)
                    : null,
                'track_points' => $request->track_points,
            ]);

            $files = $request->file('files', []);

            foreach ($files as $index => $file) {
                $path = $file->store("trails/{$trail->id}", 'public');

                if (!$path) {
                    throw new \RuntimeException('Media upload failed.');
                }

                $paths[] = $path;

                $extension = strtolower(
                    $file->getClientOriginalExtension()
                );

                TrailMedia::create([
                    'trail_id' => $trail->id,
                    'url' => Storage::url($path),
                    'type' => in_array($extension, ['mp4', 'mov'])
                        ? 'video'
                        : 'image',
                    'order' => $index,
                ]);
            }

            DB::commit();

        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            if ($coverPath) {
                Storage::disk('public')->delete($coverPath);
            }

            foreach ($paths as $path) {
                Storage::disk('public')->delete($path);
            }

            return response()->json([
                'status' => false,
                'message' => 'Failed to create trail',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred.',
            ], 500);
        }

        // Notify followers after trail creation succeeds.
        try {
            app(NotificationService::class)->notifyFollowers(
                creator: $user,
                type: 'trail_created',
                title: 'New Trail Created',
                message: trim(
                    $user->first_name . ' ' . $user->last_name
                ) . ' created a new trail: ' . $trail->name,
                relatedId: $trail->id,
                relatedType: 'trail',
                url: '/trails/' . $trail->id
            );
        } catch (\Throwable $notificationError) {
            report($notificationError);
        }

        return response()->json([
            'status' => true,
            'message' => 'Trail created successfully with auto-calculated distance',
            'data' => array_merge($this->formatTrail(
                $trail->load('media', 'creator')
            ), [
                'likes_count' => 0,
                'is_liked' => true, 
            ]),
        ], 201);
    }

    /**
     * GET /api/trails/{id}
     * Get trail details and current weather.
     */
    public function show($id)
    {
        $userId = Auth::id();
        $trail = Trail::with(['creator', 'media'])->findOrFail($id);

        $data = $this->formatTrail($trail);
        
        // Add Like Data
        $likesCount = TrailLike::where('trail_id', $trail->id)->count();
        $isLiked = false;
        if ($userId) {
            $isLiked = TrailLike::where('trail_id', $trail->id)
                ->where('user_id', $userId)
                ->exists();
        }
        
        $data['likes_count'] = $likesCount;
        $data['is_liked'] = $isLiked;

        $weather = null;

        if ($trail->latitude && $trail->longitude) {
            $apiKey = config('services.openweather.key')
                ?? env('OPENWEATHER_API_KEY');

            if ($apiKey) {
                try {
                    $response = Http::timeout(5)->get(
                        'https://api.openweathermap.org/data/2.5/weather',
                        [
                            'lat' => $trail->latitude,
                            'lon' => $trail->longitude,
                            'appid' => $apiKey,
                            'units' => 'metric',
                        ]
                    );

                    if ($response->successful()) {
                        $w = $response->json();

                        $weather = [
                            'temperature' => round($w['main']['temp'], 1) . '°C',
                            'feels_like' => round($w['main']['feels_like'], 1) . '°C',
                            'humidity' => $w['main']['humidity'] . '%',
                            'description' => ucfirst(
                                $w['weather'][0]['description']
                            ),
                            'icon' => 'https://openweathermap.org/img/wn/'
                                . $w['weather'][0]['icon'] . '@2x.png',
                            'wind_speed' => $w['wind']['speed'] . ' m/s',
                        ];
                    }
                } catch (\Throwable $e) {
                    $weather = [
                        'error' => 'Weather data unavailable',
                    ];
                }
            }
        }

        $data['weather'] = $weather;

        return response()->json([
            'status' => true,
            'message' => 'Trail details fetched successfully',
            'data' => $data,
        ]);
    }

    /**
     * GET /api/trails/{id}/community
     */
    public function community($id)
    {
        $trail = Trail::with(
            'creator:id,first_name,last_name,profile_picture'
        )->findOrFail($id);

        $today = now()->toDateString();

        $onTrailNow = DB::table('trips')
            ->join('trip_joins', 'trips.id', '=', 'trip_joins.trip_id')
            ->where('trips.trail_id', $trail->id)
            ->where('trip_joins.status', 'accepted')
            ->whereDate('trips.start_date', '<=', $today)
            ->whereDate('trips.end_date', '>=', $today)
            ->distinct()
            ->count('trip_joins.user_id');

        $totalJoined = DB::table('trips')
            ->join('trip_joins', 'trips.id', '=', 'trip_joins.trip_id')
            ->where('trips.trail_id', $trail->id)
            ->where('trip_joins.status', 'accepted')
            ->distinct()
            ->count('trip_joins.user_id');

        $upcomingTrips = Trip::where('trail_id', $trail->id)
            ->whereDate('start_date', '>=', $today)
            ->with('creator:id,first_name,last_name,profile_picture')
            ->orderBy('start_date', 'asc')
            ->limit(10)
            ->get()
            ->map(function ($trip) {
                $joinedCount = DB::table('trip_joins')
                    ->where('trip_id', $trip->id)
                    ->where('status', 'accepted')
                    ->count();

                $spotsLeft = max(
                    0,
                    (int) $trip->group_capacity - $joinedCount
                );

                $image = $trip->media()->first()?->url;

                return [
                    'id' => $trip->id,
                    'title' => $trip->title,
                    'host' => [
                        'id' => $trip->creator?->id,
                        'name' => $trip->creator
                            ? trim(
                                ($trip->creator->first_name ?? '') . ' ' .
                                ($trip->creator->last_name ?? '')
                            )
                            : 'Unknown',
                        'avatar' => $trip->creator?->profile_picture,
                    ],
                    'start_date' => $trip->start_date,
                    'end_date' => $trip->end_date,
                    'joined_count' => $joinedCount,
                    'group_capacity' => $trip->group_capacity,
                    'spots_left' => $spotsLeft,
                    'is_full' => $spotsLeft <= 0,
                    'image' => $this->absoluteUrl($image),
                    'pace' => ucfirst($trip->pace ?? 'Moderate'),
                ];
            });

        return response()->json([
            'status' => true,
            'message' => 'Community data fetched successfully',
            'data' => [
                'trail' => [
                    'id' => $trail->id,
                    'name' => $trail->name,
                    'location' => $trail->location_name,
                    'description' => $trail->description,
                    'image' => $this->absoluteUrl($trail->media_url),
                    'difficulty' => ucfirst($trail->difficulty),
                ],
                'stats' => [
                    'on_trail_now' => $onTrailNow,
                    'total_joined' => $totalJoined,
                ],
                'upcoming_trips' => $upcomingTrips,
            ],
        ]);
    }

    /**
     * PUT /api/trails/{id}
     * Update own trail and notify followers.
     */
    public function update(Request $request, $id)
    {
        $trail = Trail::findOrFail($id);
        $user = $request->user();

        if (
            !$user ||
            (int) $trail->user_id !== (int) $user->id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255',
            'location_name' => 'sometimes|string|max:255',
            'difficulty' => 'sometimes|in:easy,moderate,hard,Easy,Moderate,Hard',
            'peak_month' => 'sometimes|nullable|string|max:100',
            'description' => 'sometimes|nullable|string',
            'track_points' => 'sometimes|json',
            'elevation' => 'sometimes|numeric',
            'cover_image' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:10240',
            'remove_media_ids' => 'sometimes|array',
            'remove_media_ids.*' => 'integer|exists:trail_media,id',
            'new_files' => 'sometimes|array|max:10',
            'new_files.*' => 'file|mimes:jpg,jpeg,png,webp,mp4,mov|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation Error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $pathsToStore = [];
        $pathsToDelete = [];

        try {
            DB::beginTransaction();

            $data = $validator->validated();

            $removeIds = $data['remove_media_ids'] ?? [];
            $newFiles = $request->file('new_files', []);
            $coverFile = $request->file('cover_image');

            unset(
                $data['remove_media_ids'],
                $data['new_files'],
                $data['cover_image']
            );

            if ($coverFile) {
                $coverPath = $coverFile->store('trails/covers', 'public');

                if (!$coverPath) {
                    throw new \RuntimeException('Cover image upload failed.');
                }

                $pathsToStore[] = $coverPath;
                $data['media_url'] = Storage::url($coverPath);

                if ($trail->media_url) {
                    $oldCoverPath = str_replace(
                        '/storage/',
                        '',
                        parse_url($trail->media_url, PHP_URL_PATH)
                    );

                    if ($oldCoverPath) {
                        $pathsToDelete[] = $oldCoverPath;
                    }
                }
            }

            if (isset($data['track_points'])) {
                $points = json_decode($data['track_points'], true);

                if (!is_array($points) || count($points) < 2) {
                    throw new \RuntimeException(
                        'At least two track points are required.'
                    );
                }

                $data['distance'] = $this->calculateTotalDistance($points);
                $data['latitude'] = $points[0]['lat'] ?? $trail->latitude;
                $data['longitude'] = $points[0]['lng'] ?? $trail->longitude;
            }

            if (isset($data['difficulty'])) {
                $data['difficulty'] = strtolower($data['difficulty']);
            }

            $trail->update($data);

            if (!empty($removeIds)) {
                $mediaToRemove = TrailMedia::where('trail_id', $trail->id)
                    ->whereIn('id', $removeIds)
                    ->get();

                foreach ($mediaToRemove as $media) {
                    $path = str_replace(
                        '/storage/',
                        '',
                        parse_url($media->url, PHP_URL_PATH)
                    );

                    if ($path) {
                        $pathsToDelete[] = $path;
                    }

                    $media->delete();
                }
            }

            if (!empty($newFiles)) {
                $currentMaxOrder = TrailMedia::where(
                    'trail_id',
                    $trail->id
                )->max('order') ?? -1;

                foreach ($newFiles as $index => $file) {
                    $path = $file->store("trails/{$trail->id}", 'public');

                    if (!$path) {
                        throw new \RuntimeException('Media upload failed.');
                    }

                    $pathsToStore[] = $path;
                    $extension = strtolower(
                        $file->getClientOriginalExtension()
                    );

                    TrailMedia::create([
                        'trail_id' => $trail->id,
                        'url' => Storage::url($path),
                        'type' => in_array($extension, ['mp4', 'mov'])
                            ? 'video'
                            : 'image',
                        'order' => $currentMaxOrder + $index + 1,
                    ]);
                }
            }

            DB::commit();

        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($pathsToStore as $path) {
                Storage::disk('public')->delete($path);
            }

            return response()->json([
                'status' => false,
                'message' => 'Update failed',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred.',
            ], 500);
        }

        // Delete old media files only after the update commits.
        foreach ($pathsToDelete as $path) {
            Storage::disk('public')->delete($path);
        }

        // Notify followers after trail update succeeds.
        try {
            app(NotificationService::class)->notifyFollowers(
                creator: $user,
                type: 'trail_updated',
                title: 'Trail Updated',
                message: trim(
                    $user->first_name . ' ' . $user->last_name
                ) . ' updated the trail: ' . $trail->name,
                relatedId: $trail->id,
                relatedType: 'trail',
                url: '/trails/' . $trail->id
            );
        } catch (\Throwable $notificationError) {
            // Notification failure must not fail the trail update.
            report($notificationError);
        }

        return response()->json([
            'status' => true,
            'message' => 'Trail updated successfully',
            'data' => $this->formatTrail(
                $trail->refresh()->load('media', 'creator')
            ),
        ]);
    }

    /**
     * DELETE /api/trails/{id}
     * Delete own trail.
     */
    public function destroy(Request $request, $id)
    {
        $trail = Trail::with('media')->findOrFail($id);

        if (
            !$request->user() ||
            (int) $trail->user_id !== (int) $request->user()->id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $paths = [];

        if ($trail->media_url) {
            $coverPath = str_replace(
                '/storage/',
                '',
                parse_url($trail->media_url, PHP_URL_PATH)
            );

            if ($coverPath) {
                $paths[] = $coverPath;
            }
        }

        foreach ($trail->media as $media) {
            $path = str_replace(
                '/storage/',
                '',
                parse_url($media->url, PHP_URL_PATH)
            );

            if ($path) {
                $paths[] = $path;
            }
        }

        $trail->delete();

        foreach (array_unique($paths) as $path) {
            Storage::disk('public')->delete($path);
        }

        return response()->json([
            'status' => true,
            'message' => 'Trail deleted successfully',
        ]);
    }

    /**
     * POST /api/trails/{trail}/toggle-like
     * Toggle like/unlike for a trail
     */
    public function toggleLike(Request $request, Trail $trail)
    {
        $userId = Auth::id();
        
        // Check if already liked
        $existingLike = TrailLike::where('user_id', $userId)
            ->where('trail_id', $trail->id)
            ->first();

        if ($existingLike) {
            // Unlike: Delete the record
            $existingLike->delete();
            $isLiked = false;
            $message = 'Trail unliked successfully';
        } else {
            // Like: Create new record
            TrailLike::create([
                'user_id' => $userId,
                'trail_id' => $trail->id
            ]);
            $isLiked = true;
            $message = 'Trail liked successfully';
        }

        // Get Total Likes Count
        $likesCount = TrailLike::where('trail_id', $trail->id)->count();

        // Get Likers List (Who liked this trail)
        $likers = TrailLike::where('trail_id', $trail->id)
            ->with('user:id,first_name,last_name,profile_picture')
            ->get()
            ->map(fn($like) => [
                'id' => $like->user->id,
                'name' => trim(($like->user->first_name ?? '') . ' ' . ($like->user->last_name ?? '')),
                'avatar' => $this->absoluteUrl($like->user->profile_picture),
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => $message,
            'data' => [
                'is_liked' => $isLiked,
                'likes_count' => $likesCount,
                'likers' => $likers,
            ]
        ]);
    }

    /**
     * GET /api/trails/{trail}/likes
     * Get all likes for a trail with user details
     */
    public function getLikes(Request $request, Trail $trail)
    {
        $userId = Auth::id();

        // Total likes count
        $likesCount = TrailLike::where('trail_id', $trail->id)->count();

        // Check if current user liked this trail
        $isLiked = false;
        if ($userId) {
            $isLiked = TrailLike::where('trail_id', $trail->id)
                ->where('user_id', $userId)
                ->exists();
        }

        // Get all likers with their details (Name, Avatar, Bio)
        $likers = TrailLike::where('trail_id', $trail->id)
            ->with('user:id,first_name,last_name,profile_picture,bio')
            ->latest() // Newest likes first
            ->get()
            ->map(fn($like) => [
                'id' => $like->user->id,
                'name' => trim(($like->user->first_name ?? '') . ' ' . ($like->user->last_name ?? '')),
                'avatar' => $this->absoluteUrl($like->user->profile_picture),
                'bio' => $like->user->bio,
                'liked_at' => $like->created_at,
            ])
            ->values();

        return response()->json([
            'status' => true,
            'message' => 'Trail likes fetched successfully',
            'data' => [
                'trail_id' => $trail->id,
                'likes_count' => $likesCount,
                'is_liked' => $isLiked,
                'likers' => $likers,
            ]
        ]);
    }

    /**
     * Calculate total distance between track points.
     */
    private function calculateTotalDistance(array $points): float
    {
        if (count($points) < 2) {
            return 0.0;
        }

        $totalDistance = 0.0;

        for ($i = 0; $i < count($points) - 1; $i++) {
            $point1 = $points[$i];
            $point2 = $points[$i + 1];

            if (
                !isset($point1['lat'], $point1['lng']) ||
                !isset($point2['lat'], $point2['lng'])
            ) {
                continue;
            }

            $totalDistance += $this->getHaversineDistance(
                (float) $point1['lat'],
                (float) $point1['lng'],
                (float) $point2['lat'],
                (float) $point2['lng']
            );
        }

        return round($totalDistance, 2);
    }

    private function getHaversineDistance(
        float $lat1,
        float $lon1,
        float $lat2,
        float $lon2
    ): float {
        $earthRadius = 6371;

        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);

        $a = sin($dLat / 2) * sin($dLat / 2)
            + cos(deg2rad($lat1))
            * cos(deg2rad($lat2))
            * sin($dLon / 2)
            * sin($dLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadius * $c;
    }

    /**
     * Format trail response.
     */
    private function formatTrail(Trail $trail): array
    {
        $media = $trail->media->sortBy('order')->values();

        $popularity = DB::table('trip_joins')
            ->join('trips', 'trip_joins.trip_id', '=', 'trips.id')
            ->where('trips.trail_id', $trail->id)
            ->where('trip_joins.status', 'accepted')
            ->distinct()
            ->count('trip_joins.user_id');

        $avgRating = $trail->reviews()->avg('rating') ?? 0;

        return [
            'id' => $trail->id,
            'name' => $trail->name,
            'location_name' => $trail->location_name,
            'latitude' => $trail->latitude,
            'longitude' => $trail->longitude,
            'distance' => $trail->distance . ' km',
            'elevation' => $trail->elevation . ' m',
            'difficulty' => ucfirst($trail->difficulty),
            'peak_month' => $trail->peak_month,
            'description' => $trail->description,
            'image' => $this->absoluteUrl($trail->media_url),

            'media' => $media->map(fn ($item) => [
                'id' => $item->id,
                'url' => $this->absoluteUrl($item->url),
                'type' => $item->type,
                'order' => $item->order,
            ])->values(),

            'stats' => [
                'popularity' => $popularity,
                'avg_rating' => round((float) $avgRating, 1),
            ],

            'creator' => [
                'id' => $trail->creator?->id,
                'name' => $trail->creator
                    ? trim(
                        $trail->creator->first_name . ' ' .
                        $trail->creator->last_name
                    )
                    : 'Unknown',
                'avatar' => $trail->creator?->profile_picture,
            ],

            'created_at' => $trail->created_at,
            'updated_at' => $trail->updated_at,

            'distance_from_user' => isset($trail->distance_from_user)
                ? round($trail->distance_from_user, 2) . ' km'
                : null,
        ];
    }

    /**
     * Convert relative storage paths to absolute URLs.
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