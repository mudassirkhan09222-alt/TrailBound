<?php

namespace App\Http\Controllers;

use App\Models\Trip;
use App\Models\TripMedia;
use App\Models\TripLike;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Auth;
use App\Services\NotificationService;

class TripController extends Controller
{
    // GET /api/trips
    public function index()
    {
        $userId = Auth::id();
        
        $trips = Trip::with([
            'creator:id,first_name,last_name,profile_picture',
            'media',
        ])
            ->withCount([
                'members as joined_count' => function ($query) {
                    $query->where('trip_joins.status', 'accepted');
                },
                'likes as likes_count', // Add likes count
            ])
            ->latest()
            ->get();

        // Get liked trip IDs for current user
        $likedTripIds = [];
        if ($userId) {
            $likedTripIds = TripLike::where('user_id', $userId)
                ->whereIn('trip_id', $trips->pluck('id'))
                ->pluck('trip_id')
                ->toArray();
        }

        return response()->json([
            'status' => true,
            'message' => 'Trips fetched successfully',
            'data' => $trips->map(function ($trip) use ($likedTripIds) {
                $totalJoined = 1 + (int) $trip->joined_count;

                $spotsLeft = max(
                    0,
                    (int) $trip->group_capacity - $totalJoined
                );

                return array_merge($this->formatTrip($trip), [
                    'creator' => $this->creatorData($trip),
                    'joined_count' => $totalJoined,
                    'joined_text' => "{$totalJoined}/{$trip->group_capacity} Joined",
                    'spots_left' => $spotsLeft,
                    'is_full' => $spotsLeft <= 0,
                    'likes_count' => $trip->likes_count,
                    'is_liked' => in_array($trip->id, $likedTripIds),
                ]);
            })->values(),
        ]);
    }

    // POST /api/trips
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'description' => 'nullable|string',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'group_capacity' => 'required|integer|min:1',
            'pace' => 'required|in:easy,moderate,hard,Easy,Moderate,Hard',
            'location_name' => 'nullable|string|max:255',
            'location' => 'nullable|string|max:255',
            'trail_id' => 'nullable|integer|exists:trails,id',
            'tags' => 'nullable|array',
            'tags.*' => 'string|max:100',
            'itinerary' => 'nullable',
            'files' => 'nullable|array|max:10',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp,mp4,mov|max:10240',
            'image' => 'nullable|image|mimes:jpg,jpeg,png,webp|max:10240',
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

        try {
            DB::beginTransaction();

            $tripData = [
                'user_id' => $user->id,
                'description' => $request->description,
                'start_date' => $request->start_date,
                'end_date' => $request->end_date,
                'group_capacity' => $request->group_capacity,
                'pace' => strtolower($request->pace),
            ];

            $location = $request->input(
                'location_name',
                $request->input('location')
            );

            if (Schema::hasColumn('trips', 'location_name')) {
                $tripData['location_name'] = $location;
            }

            if (Schema::hasColumn('trips', 'trail_id')) {
                $tripData['trail_id'] = $request->input('trail_id');
            }

            if (Schema::hasColumn('trips', 'tags')) {
                $tripData['tags'] = $request->input('tags');
            }

            if (Schema::hasColumn('trips', 'itinerary')) {
                $tripData['itinerary'] = $request->input('itinerary');
            }

            if (Schema::hasColumn('trips', 'media_url')) {
                $tripData['media_url'] = null;
            }

            if (Schema::hasColumn('trips', 'is_completed')) {
                $tripData['is_completed'] = false;
            }

            $trip = Trip::create($tripData);

            $files = $request->file('files', []);

            if ($request->hasFile('image')) {
                $files[] = $request->file('image');
            }

            foreach ($files as $index => $file) {
                $path = $file->store("trips/{$trip->id}", 'public');

                if (!$path) {
                    throw new \RuntimeException('File upload failed.');
                }

                $paths[] = $path;

                $extension = strtolower(
                    $file->getClientOriginalExtension()
                );

                TripMedia::create([
                    'trip_id' => $trip->id,
                    'url' => Storage::url($path),
                    'type' => in_array($extension, ['mp4', 'mov'])
                        ? 'video'
                        : 'image',
                    'order' => $index,
                ]);
            }

            DB::commit();

            // Notification: trip create hone par followers ko notify karo.
            try {
                app(NotificationService::class)->notifyFollowers(
                    creator: $user,
                    type: 'trip_created',
                    title: 'New Trip Created',
                    message: trim(
                        $user->first_name . ' ' . $user->last_name
                    ) . ' created a new trip.',
                    relatedId: $trip->id,
                    relatedType: 'trip',
                    url: '/trips/' . $trip->id
                );
            } catch (\Throwable $notificationError) {
                report($notificationError);
            }

            $trip->load([
                'media',
                'creator:id,first_name,last_name,profile_picture',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Trip created successfully',
                'data' => array_merge($this->formatTrip($trip), [
                    'creator' => $this->creatorData($trip),
                    'joined_count' => 1,
                    'joined_text' => "1/{$trip->group_capacity} Joined",
                    'spots_left' => max(0, (int) $trip->group_capacity - 1),
                    'is_full' => (int) $trip->group_capacity <= 1,
                    'likes_count' => 0,
                    'is_liked' => true, // Creator always likes their own trip initially or set false based on requirement
                ]),
            ], 201);

        } catch (\Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            foreach ($paths as $path) {
                Storage::disk('public')->delete($path);
            }

            return response()->json([
                'status' => false,
                'message' => 'Failed to create trip',
                'error' => config('app.debug')
                    ? $e->getMessage()
                    : 'An unexpected error occurred.',
            ], 500);
        }
    }

    // GET /api/trips/{id}
    public function show(Request $request, $id)
    {
        $trip = Trip::with([
            'creator:id,first_name,last_name,profile_picture,bio',
            'members:id,first_name,last_name,profile_picture',
            'media',
        ])->findOrFail($id);

        $data = $this->formatTrip($trip);
        $data['creator'] = $this->creatorData($trip);

        $acceptedMembers = $trip->members
            ->filter(fn ($member) =>
                $member->pivot->status === 'accepted'
            )
            ->values();

        $data['members'] = $acceptedMembers->map(fn ($member) => [
            'id' => $member->id,
            'name' => trim(
                $member->first_name . ' ' . $member->last_name
            ),
            'avatar' => $member->profile_picture,
            'total_trips' => $member->total_trips ?? 0,
            'total_hikes' => $member->total_hikes ?? 0,
        ])->values();

        $userId = $request->user()?->id;

        $hasJoined = $userId
            ? $acceptedMembers->contains('id', $userId)
            : false;

        $totalJoined = 1 + $acceptedMembers->count();

        $spotsLeft = max(
            0,
            (int) $trip->group_capacity - $totalJoined
        );

        // Like Data for Show Endpoint
        $likesCount = TripLike::where('trip_id', $trip->id)->count();
        $isLiked = false;
        if ($userId) {
            $isLiked = TripLike::where('trip_id', $trip->id)
                ->where('user_id', $userId)
                ->exists();
        }

        $data['joined_count'] = $totalJoined;
        $data['joined_text'] = "{$totalJoined}/{$trip->group_capacity} Joined";

        $data['can_join'] = $userId !== null
            && $spotsLeft > 0
            && !$hasJoined
            && (int) $userId !== (int) $trip->user_id;

        $data['has_joined'] = $hasJoined;
        $data['is_owner'] = $userId !== null
            && (int) $userId === (int) $trip->user_id;
        $data['spots_left'] = $spotsLeft;
        $data['is_full'] = $spotsLeft <= 0;
        
        $data['likes_count'] = $likesCount;
        $data['is_liked'] = $isLiked;

        return response()->json([
            'status' => true,
            'message' => 'Trip details fetched successfully',
            'data' => $data,
        ]);
    }

    // PUT/PATCH /api/trips/{id}
    public function update(Request $request, $id)
    {
        $trip = Trip::findOrFail($id);

        if (
            !$request->user()
            || (int) $trip->user_id !== (int) $request->user()->id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'description' => 'sometimes|nullable|string',
            'start_date' => 'sometimes|required|date',
            'end_date' => 'sometimes|required|date|after_or_equal:start_date',
            'group_capacity' => 'sometimes|required|integer|min:1',
            'pace' => 'sometimes|required|in:easy,moderate,hard,Easy,Moderate,Hard',
            'location_name' => 'sometimes|nullable|string|max:255',
            'location' => 'sometimes|nullable|string|max:255',
            'trail_id' => 'sometimes|nullable|integer|exists:trails,id',
            'tags' => 'sometimes|nullable|array',
            'tags.*' => 'string|max:100',
            'itinerary' => 'sometimes|nullable',
            'cover_image' => 'sometimes|image|mimes:jpg,jpeg,png,webp|max:10240',
            'remove_media_ids' => 'sometimes|array',
            'remove_media_ids.*' => 'integer',
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

        $user = $request->user();

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

            if (array_key_exists('location', $data)) {
                if (Schema::hasColumn('trips', 'location_name')) {
                    $data['location_name'] = $data['location_name']
                        ?? $data['location'];
                }

                unset($data['location']);
            }

            if (isset($data['pace'])) {
                $data['pace'] = strtolower($data['pace']);
            }

            $allowedColumns = [
                'description',
                'start_date',
                'end_date',
                'group_capacity',
                'pace',
                'location_name',
                'trail_id',
                'tags',
                'itinerary',
            ];

            $data = array_intersect_key(
                $data,
                array_flip(array_filter(
                    $allowedColumns,
                    fn ($column) => Schema::hasColumn('trips', $column)
                ))
            );

            if ($coverFile) {
                $path = $coverFile->store("trips/{$trip->id}", 'public');

                if (!$path) {
                    throw new \RuntimeException('Cover image upload failed.');
                }

                $pathsToStore[] = $path;

                $extension = strtolower(
                    $coverFile->getClientOriginalExtension()
                );

                TripMedia::create([
                    'trip_id' => $trip->id,
                    'url' => Storage::url($path),
                    'type' => in_array($extension, ['mp4', 'mov'])
                        ? 'video'
                        : 'image',
                    'order' => 0,
                ]);
            }

            if (!empty($data)) {
                $trip->update($data);
            }

            if (!empty($removeIds)) {
                $mediaToRemove = TripMedia::where('trip_id', $trip->id)
                    ->whereIn('id', $removeIds)
                    ->get();

                foreach ($mediaToRemove as $media) {
                    $path = $this->storagePath($media->url);

                    if (
                        $path
                        && Storage::disk('public')->exists($path)
                    ) {
                        $pathsToDelete[] = $path;
                    }

                    $media->delete();
                }
            }

            if (!empty($newFiles)) {
                $currentMaxOrder = TripMedia::where('trip_id', $trip->id)
                    ->max('order') ?? -1;

                foreach ($newFiles as $index => $file) {
                    $path = $file->store("trips/{$trip->id}", 'public');

                    if (!$path) {
                        throw new \RuntimeException('File upload failed.');
                    }

                    $pathsToStore[] = $path;

                    $extension = strtolower(
                        $file->getClientOriginalExtension()
                    );

                    TripMedia::create([
                        'trip_id' => $trip->id,
                        'url' => Storage::url($path),
                        'type' => in_array($extension, ['mp4', 'mov'])
                            ? 'video'
                            : 'image',
                        'order' => $currentMaxOrder + $index + 1,
                    ]);
                }
            }

            DB::commit();

            // Notification: trip update hone par followers ko notify karo.
            try {
                $updatedTrip = $trip->fresh();

                $tripName = $updatedTrip?->location_name ?: 'a trip';

                app(NotificationService::class)->notifyFollowers(
                    creator: $user,
                    type: 'trip_updated',
                    title: 'Trip Updated',
                    message: trim(
                        $user->first_name . ' ' . $user->last_name
                    ) . ' updated the trip: ' . $tripName,
                    relatedId: $trip->id,
                    relatedType: 'trip',
                    url: '/trips/' . $trip->id
                );
            } catch (\Throwable $notificationError) {
                report($notificationError);
            }

            foreach ($pathsToDelete as $path) {
                Storage::disk('public')->delete($path);
            }

            $trip->load([
                'media',
                'creator:id,first_name,last_name,profile_picture',
            ]);

            return response()->json([
                'status' => true,
                'message' => 'Trip updated successfully',
                'data' => array_merge($this->formatTrip($trip), [
                    'creator' => $this->creatorData($trip),
                ]),
            ]);

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
    }

    // DELETE /api/trips/{id}
    public function destroy(Request $request, $id)
    {
        $trip = Trip::with('media')->findOrFail($id);

        if (
            !$request->user()
            || (int) $trip->user_id !== (int) $request->user()->id
        ) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthorized action',
            ], 403);
        }

        $paths = $trip->media
            ->map(fn ($item) => $this->storagePath($item->url))
            ->filter()
            ->values();

        $trip->delete();

        foreach ($paths as $path) {
            Storage::disk('public')->delete($path);
        }

        return response()->json([
            'status' => true,
            'message' => 'Trip deleted successfully',
        ]);
    }

    // GET /api/users/{userId}/trips
    public function userTrips($userId)
    {
        $currentUserId = Auth::id();
        
        $trips = Trip::where('user_id', $userId)
            ->with([
                'creator:id,first_name,last_name,profile_picture',
                'media',
            ])
            ->withCount([
                'members as joined_count' => function ($query) {
                    $query->where('trip_joins.status', 'accepted');
                },
                'likes as likes_count',
            ])
            ->latest()
            ->get();

        // Get liked trip IDs for current user
        $likedTripIds = [];
        if ($currentUserId) {
            $likedTripIds = TripLike::where('user_id', $currentUserId)
                ->whereIn('trip_id', $trips->pluck('id'))
                ->pluck('trip_id')
                ->toArray();
        }

        return response()->json([
            'status' => true,
            'message' => 'User trips fetched successfully',
            'data' => $trips->map(function ($trip) use ($likedTripIds) {
                $totalJoined = 1 + (int) $trip->joined_count;

                $spotsLeft = max(
                    0,
                    (int) $trip->group_capacity - $totalJoined
                );

                return array_merge($this->formatTrip($trip), [
                    'creator' => $this->creatorData($trip),
                    'joined_count' => $totalJoined,
                    'joined_text' => "{$totalJoined}/{$trip->group_capacity} Joined",
                    'spots_left' => $spotsLeft,
                    'is_full' => $spotsLeft <= 0,
                    'likes_count' => $trip->likes_count,
                    'is_liked' => in_array($trip->id, $likedTripIds),
                ]);
            })->values(),
        ]);
    }

    // POST /api/trips/{trip}/toggle-like
    public function toggleLike(Request $request, Trip $trip)
    {
        $userId = Auth::id();
        
        // Check if already liked
        $existingLike = TripLike::where('user_id', $userId)
            ->where('trip_id', $trip->id)
            ->first();

        if ($existingLike) {
            // Unlike: Delete the record
            $existingLike->delete();
            $isLiked = false;
            $message = 'Trip unliked successfully';
        } else {
            // Like: Create new record
            TripLike::create([
                'user_id' => $userId,
                'trip_id' => $trip->id
            ]);
            $isLiked = true;
            $message = 'Trip liked successfully';
        }

        // Get Total Likes Count
        $likesCount = TripLike::where('trip_id', $trip->id)->count();

        // Get Likers List (Who liked this trip)
        $likers = TripLike::where('trip_id', $trip->id)
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

    // GET /api/trips/{trip}/likes
    public function getLikes(Request $request, Trip $trip)
    {
        $userId = Auth::id();

        // Total likes count
        $likesCount = TripLike::where('trip_id', $trip->id)->count();

        // Check if current user liked this trip
        $isLiked = false;
        if ($userId) {
            $isLiked = TripLike::where('trip_id', $trip->id)
                ->where('user_id', $userId)
                ->exists();
        }

        // Get all likers with their details (Name, Avatar, Bio)
        $likers = TripLike::where('trip_id', $trip->id)
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
            'message' => 'Trip likes fetched successfully',
            'data' => [
                'trip_id' => $trip->id,
                'likes_count' => $likesCount,
                'is_liked' => $isLiked,
                'likers' => $likers,
            ]
        ]);
    }

    // Format trip response
    private function formatTrip(Trip $trip): array
    {
        $media = $trip->relationLoaded('media')
            ? $trip->media->sortBy('order')->values()
            : $trip->media()->orderBy('order')->get();

        $startDate = $trip->start_date
            ? substr((string) $trip->start_date, 0, 10)
            : null;

        $endDate = $trip->end_date
            ? substr((string) $trip->end_date, 0, 10)
            : null;

        $today = now()->toDateString();

        $onTrailNow = $startDate !== null
            && $endDate !== null
            && $today >= $startDate
            && $today <= $endDate;

        $firstMediaUrl = $media->first()?->url;

        $legacyMediaUrl = Schema::hasColumn('trips', 'media_url')
            ? $trip->getAttribute('media_url')
            : null;

        return [
            'id' => $trip->id,
            'user_id' => $trip->user_id,
            'trail_id' => Schema::hasColumn('trips', 'trail_id')
                ? $trip->getAttribute('trail_id')
                : null,
            'description' => $trip->description,
            'start_date' => $trip->start_date,
            'end_date' => $trip->end_date,
            'on_trail_now' => $onTrailNow ? 1 : 0,
            'group_capacity' => $trip->group_capacity,
            'pace' => $trip->pace,
            'location_name' => Schema::hasColumn('trips', 'location_name')
                ? $trip->getAttribute('location_name')
                : null,
            'tags' => Schema::hasColumn('trips', 'tags')
                ? ($trip->getAttribute('tags') ?? [])
                : [],
            'itinerary' => Schema::hasColumn('trips', 'itinerary')
                ? $trip->getAttribute('itinerary')
                : null,
            'is_completed' => Schema::hasColumn('trips', 'is_completed')
                ? (bool) $trip->getAttribute('is_completed')
                : false,
            'image' => $this->absoluteUrl(
                $firstMediaUrl ?: $legacyMediaUrl
            ),
            'media' => $media->map(fn ($item) => [
                'id' => $item->id,
                'url' => $this->absoluteUrl($item->url),
                'type' => $item->type,
                'order' => $item->order,
            ])->values(),
            'created_at' => $trip->created_at,
            'updated_at' => $trip->updated_at,
        ];
    }

    private function creatorData(Trip $trip): array
    {
        return [
            'id' => $trip->creator?->id,
            'name' => $trip->creator
                ? trim(
                    $trip->creator->first_name . ' '
                    . $trip->creator->last_name
                )
                : 'Unknown',
            'avatar' => $trip->creator?->profile_picture,
            'bio' => $trip->creator?->bio,
        ];
    }

    private function absoluteUrl(?string $url): ?string
    {
        if (!$url) {
            return null;
        }

        if (
            str_starts_with($url, 'http://')
            || str_starts_with($url, 'https://')
        ) {
            return $url;
        }

        return url('/' . ltrim($url, '/'));
    }

    private function storagePath(?string $url): ?string
    {
        $path = parse_url($url ?? '', PHP_URL_PATH);

        if (!$path || !str_starts_with($path, '/storage/')) {
            return null;
        }

        return substr($path, strlen('/storage/'));
    }
}