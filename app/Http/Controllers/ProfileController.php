<?php

namespace App\Http\Controllers;

use App\Models\SupportTicket; // ✅ Added for saving tickets to DB
use App\Models\Trip;
use App\Models\User;
use GuzzleHttp\Promise\Create;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class ProfileController extends Controller
{
    /**
     * GET /api/profiles
     * Profiles ki listing.
     */
    public function index(Request $request): JsonResponse
    {
        $currentUser = $request->user();

        $users = User::when(
            $currentUser,
            fn ($query) => $query->where('id', '!=', $currentUser->id)
        )
            ->latest()
            ->paginate(20);

        $data = $users->getCollection()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => trim(
                    ($user->first_name ?? '') . ' ' .
                    ($user->last_name ?? '')
                ),
                'avatar_url' => $this->absoluteUrl($user->profile_picture),
                'bio' => $user->bio,
                'total_hikes' => $user->total_hikes ?? 0,
                'looking_for_partner' => (bool) ($user->looking_for_partner ?? false),
                'tags' => $this->generateProfileTags($user),
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Profiles fetched successfully',
            'data' => $data,
            'pagination' => [
                'current_page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'last_page' => $users->lastPage(),
            ],
        ]);
    }

    /**
     * GET /api/profiles/{id}
     * Kisi bhi user ki profile.
     */
    public function showById($id): JsonResponse
    { 
        $user = User::find($id);
 

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'User not found',
            ], 404);
        }

        $createdTrips = Trip::where('user_id', $user->id)
            ->orderByDesc('start_date')
            ->limit(10)
            ->get();

        $joinedTripIds = $user->joinedTrips()
            ->wherePivot('status', 'accepted')
            ->pluck('trips.id');

        $joinedTrips = Trip::whereIn('id', $joinedTripIds)
            ->orderByDesc('start_date')
            ->limit(10)
            ->get();

        $allTrips = $createdTrips
            ->merge($joinedTrips)
            ->unique('id')
            ->sortByDesc('start_date')
            ->take(5)
            ->values();

        $tripsData = $allTrips->map(function ($trip) {
            $acceptedCount = DB::table('trip_joins')
                ->where('trip_id', $trip->id)
                ->where('status', 'accepted')
                ->count();

            $creator = User::find($trip->user_id);

            return [
                'id' => $trip->id,
                'user_id' => $trip->user_id,
                'title' => $trip->title,
                'description' => $trip->description,
                'start_date' => $trip->start_date,
                'end_date' => $trip->end_date,
                'pace' => $trip->pace,
                'group_capacity' => $trip->group_capacity,
                'location_name' => $trip->location_name ?? null,
                'latitude' => $trip->latitude ?? null,
                'longitude' => $trip->longitude ?? null,
                'image_url' => $this->absoluteUrl($trip->media_url ?? null),
                'joined_count' => $acceptedCount,
                'spots_left' => max(
                    0,
                    (int) $trip->group_capacity - $acceptedCount
                ),
                'creator' => $creator ? [
                    'id' => $creator->id,
                    'name' => trim(
                        ($creator->first_name ?? '') . ' ' .
                        ($creator->last_name ?? '')
                    ),
                    'avatar_url' => $this->absoluteUrl(
                        $creator->profile_picture
                    ),
                ] : null,
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Profile fetched successfully',
            'data' => [
                'id' => $user->id,
                'name' => trim(
                    ($user->first_name ?? '') . ' ' .
                    ($user->last_name ?? '')
                ),
                'avatar_url' => $this->absoluteUrl($user->profile_picture),
                'bio' => $user->bio,
                'tags' => $this->generateProfileTags($user),
                'looking_for_partner' => (bool) ($user->looking_for_partner ?? false),
                'stats' => [
                    'trails' => $user->total_hikes ?? 0,
                    'miles' => $user->total_miles ?? 0,
                    'reviews' => $user->reviews_count ?? 0,
                ],
                'recent_trips' => $tripsData,
            ],
        ]);
    }

    /**
     * GET /api/profile
     * Logged-in user ki apni profile.
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        return response()->json([
            'status' => true,
            'message' => 'Profile fetched successfully',
            'data' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => trim(
                    ($user->first_name ?? '') . ' ' .
                    ($user->last_name ?? '')
                ),
                'email' => $user->email,
                'phone' => $user->phone,
                'location' => $user->location,
                'latitude' => $user->latitude,
                'longitude' => $user->longitude,
                'avatar_url' => $this->absoluteUrl($user->profile_picture),
                'bio' => $user->bio,
                'total_hikes' => $user->total_hikes ?? 0,
                'total_miles' => $user->total_miles ?? 0,
                'reviews_count' => $user->reviews_count ?? 0,
                'looking_for_partner' => (bool) ($user->looking_for_partner ?? false),
                'tags' => $this->generateProfileTags($user),
                'created_at' => $user->created_at,
            ],
        ]);
    }

    /**
     * POST /api/profile/update
     * Edit Profile Screen ke mutabiq update logic
     */
    public function update(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Validation Rules (UI fields ke hisaab se)
        $validator = Validator::make($request->all(), [
            'first_name'    => 'sometimes|required|string|max:255',
            'last_name'     => 'sometimes|required|string|max:255',
            
            // Email unique hona chahiye siwaye current user ke
            'email'         => 'sometimes|required|email|unique:users,email,' . $user->id,
            
            // Phone number validation
            'phone'         => 'sometimes|nullable|string|max:20',
            'country_code'  => 'sometimes|nullable|string|max:10',
            
            // Profile Picture
            'profile_picture' => 'sometimes|nullable|image|mimes:jpg,jpeg,png,webp|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Image Upload Logic (Same as before)
        if ($request->hasFile('profile_picture')) {
            if ($user->profile_picture) {
                $oldPath = $user->profile_picture;
                if (str_starts_with($oldPath, 'http')) {
                    $oldPath = parse_url($oldPath, PHP_URL_PATH) ?? '';
                }
                $oldPath = preg_replace('#^/?storage/#', '', $oldPath);
                
                if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }
            $data['profile_picture'] = $request->file('profile_picture')->store('profiles', 'public');
        } elseif ($request->exists('profile_picture') && $request->input('profile_picture') === null) {
            // Agar frontend ne null bheja hai to image delete karein
            if ($user->profile_picture) {
                $oldPath = $user->profile_picture;
                if (str_starts_with($oldPath, 'http')) {
                    $oldPath = parse_url($oldPath, PHP_URL_PATH) ?? '';
                }
                $oldPath = preg_replace('#^/?storage/#', '', $oldPath);
                
                if ($oldPath && Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }
            $data['profile_picture'] = null;
        }

        // Database Update
        $user->update($data);
        $user->refresh();

        return response()->json([
            'status' => true,
            'message' => 'Profile updated successfully',
            'data' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'email' => $user->email,
                'phone' => $user->phone,
                'country_code' => $user->country_code ?? '+1',
                'avatar_url' => $this->absoluteUrl($user->profile_picture),
            ],
        ]);
    }

    /**
     * POST /api/profile/change-password
     * Change Password Screen ke mutabiq logic
     */
    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'status' => false,
                'message' => 'Unauthenticated',
            ], 401);
        }

        // Validation Rules
        $validator = Validator::make($request->all(), [
            'current_password' => 'required|string',
            'new_password'     => 'required|string|min:8|confirmed', 
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        // 1. Verify Current Password
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'status' => false,
                'message' => 'Current password is incorrect',
            ], 403);
        }

        // 2. Update with New Password
        $user->update([
            'password' => Hash::make($request->new_password),
        ]);

        return response()->json([
            'status' => true,
            'message' => 'Password changed successfully',
        ]);
    }

    /**
     * GET /api/help/faqs
     * Help & Support FAQs Screen
     */
    public function getFaqs(): JsonResponse
    {
        // Filhal dummy data. Baad mein database table 'faqs' se replace karein
        $faqs = [
            [
                'id' => 1,
                'question' => 'How do I join a hiking trip?',
                'answer' => 'Browse available trips on the home screen and click "Join" on any trip that matches your schedule.',
            ],
            [
                'id' => 2,
                'question' => 'Is Trail Bound free to use?',
                'answer' => 'Yes, basic features are free. Premium route planning will be available soon.',
            ],
            [
                'id' => 3,
                'question' => 'How do I cancel my booking?',
                'answer' => 'Go to My Trips > Active Trips > Select Trip > Cancel Booking. Free up to 24 hours before.',
            ],
            [
                'id' => 4,
                'question' => 'What safety measures are in place?',
                'answer' => 'All hosts are verified. We provide emergency contact sharing and real-time tracking.',
            ],
        ];

        return response()->json([
            'status' => true,
            'message' => 'FAQs fetched successfully',
            'data' => $faqs,
        ]);
    }

    /**
     * POST /api/help/support
     * Help & Support Contact Form Screen (Database Save)
     */
    public function submitSupport(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name'      => 'required|string|max:255',
            'email'     => 'required|email|max:255',
            'concern'   => 'required|string|in:Account Issue,Trip Problem,Payment,Bug Report,Other',
            'message'   => 'required|string|min:10|max:5000',
            'attachment'=> 'sometimes|nullable|file|mimes:jpg,jpeg,png,pdf,mp4,mov|max:10240',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => false,
                'message' => 'Validation failed',
                'errors' => $validator->errors(),
            ], 422);
        }

        $data = $validator->validated();

        // Handle File Upload
        if ($request->hasFile('attachment')) {
            $data['attachment_path'] = $request->file('attachment')->store('support_tickets', 'public');
        }

        // ✅ Database Mein Save Karein
        $ticket = SupportTicket::create([
            'name'            => $data['name'],
            'email'           => $data['email'],
            'concern'         => $data['concern'],
            'message'         => $data['message'],
            'attachment_path' => $data['attachment_path'] ?? null,
            'user_id'         => $request->user()?->id,
            'status'          => 'pending',
        ]);

        return response()->json([
            'status'  => true,
            'message' => 'Support request submitted successfully.',
            'data'    => [
                'ticket_id' => $ticket->id,
                'status'    => $ticket->status,
            ]
        ]);
    }

    /**
     * GET /api/legal/terms
     * Terms & Conditions Screen
     */
    public function getTerms(): JsonResponse
    {
        // Static text ya database se fetch karein
        $termsText = "This is dummy copy for Terms & Conditions. It is not meant to be read. It has been placed here solely to demonstrate the look and feel of finished, typeset text...";

        return response()->json([
            'status' => true,
            'message' => 'Terms fetched successfully',
            'data' => [
                'title' => 'Terms & Conditions',
                'content' => $termsText,
                'version' => '1.0.0',
                'last_updated' => now()->toDateString(),
            ],
        ]);
    }

    /**
     * GET /api/partners
     * Hiking partner dhoondne wale users.
     */
    public function partners(Request $request): JsonResponse
    {
        $perPage = max(
            1,
            min((int) $request->input('per_page', 15), 50)
        );

        $partners = User::where('looking_for_partner', true)
            ->select(
                'id',
                'first_name',
                'last_name',
                'profile_picture',
                'total_hikes',
                'bio',
                'looking_for_partner'
            )
            ->latest()
            ->paginate($perPage);

        $data = $partners->getCollection()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => trim(
                    ($user->first_name ?? '') . ' ' .
                    ($user->last_name ?? '')
                ),
                'avatar_url' => $this->absoluteUrl($user->profile_picture),
                'total_hikes' => $user->total_hikes ?? 0,
                'bio' => $user->bio,
                'looking_for_partner' => true,
                'tags' => $this->generateProfileTags($user),
            ];
        })->values();

        return response()->json([
            'status' => true,
            'message' => 'Partners fetched successfully',
            'data' => $data,
            'pagination' => [
                'current_page' => $partners->currentPage(),
                'per_page' => $partners->perPage(),
                'total' => $partners->total(),
                'last_page' => $partners->lastPage(),
            ],
        ]);
    }

    /**
     * Activity ki bunyaad par automatic tags.
     */
    private function generateProfileTags(User $user): array
    {
        $tags = [];

        $createdPaces = Trip::where('user_id', $user->id)
            ->pluck('pace');

        $joinedPaces = $user->joinedTrips()
            ->wherePivot('status', 'accepted')
            ->pluck('trips.pace');

        $allPaces = $createdPaces
            ->merge($joinedPaces)
            ->map(fn ($pace) => strtolower(trim((string) $pace)));

        $paceCounts = [
            'easy' => $allPaces->filter(fn ($pace) => $pace === 'easy')->count(),
            'moderate' => $allPaces->filter(fn ($pace) => $pace === 'moderate')->count(),
            'hard' => $allPaces->filter(fn ($pace) => $pace === 'hard')->count(),
        ];

        $maxCount = max($paceCounts);

        if ($maxCount > 0) {
            if ($paceCounts['easy'] === $maxCount) {
                $tags[] = 'Easy Hiker';
            }

            if ($paceCounts['moderate'] === $maxCount) {
                $tags[] = 'Moderate Hiker';
            }

            if ($paceCounts['hard'] === $maxCount) {
                $tags[] = 'Hard Hiker';
            }
        }

        if ((bool) ($user->looking_for_partner ?? false)) {
            $tags[] = 'Looking for Partner';
        }

        $hostedTrips = Trip::where('user_id', $user->id)
            ->get(['id', 'group_capacity']);

        $totalCapacity = 0;
        $totalAccepted = 0;

        foreach ($hostedTrips as $trip) {
            $totalCapacity += max(0, (int) $trip->group_capacity);

            $totalAccepted += DB::table('trip_joins')
                ->where('trip_id', $trip->id)
                ->where('status', 'accepted')
                ->count();
        }

        if ($totalCapacity > 0) {
            $occupancy = ($totalAccepted / $totalCapacity) * 100;

            if ($occupancy < 50) {
                $tags[] = 'Needs Hiking Buddies';
            } elseif ($occupancy >= 70) {
                $tags[] = 'Popular Trip Host';
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * Image path ko full URL banayein.
     */
    private function absoluteUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (
            str_starts_with($path, 'https://') ||
            str_starts_with($path, 'http://')
        ) {
            return $path;
        }

        $path = ltrim($path, '/');

        if (str_starts_with($path, 'storage/')) {
            return url('/' . $path);
        }

        return url('/storage/' . $path);
    }
}