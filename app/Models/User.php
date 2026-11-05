<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'first_name',
        'last_name',
        'email',
        'phone',
        'location',
        'latitude',
        'longitude',
        'password',
        'bio',
        'profile_picture',
        
        // Hiker Profile Fields
        'looking_for_partner',
        'total_hikes',
        'tags',
        'total_miles',
        'reviews_count',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'looking_for_partner' => 'boolean',
            'tags' => 'array',
            'total_miles' => 'decimal:2', 
        ];
    }

    // --- Existing Relationships (Trips & Favorites) ---

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_trips')->withTimestamps();
    }

    public function trips(): BelongsToMany
    {
        return $this->belongsToMany(Trip::class, 'user_trips')->withTimestamps();
    }

    public function hikes(): BelongsToMany
    {
        return $this->trips();
    }

    public function joinedTrips(): BelongsToMany
    {
        return $this->belongsToMany(Trip::class, 'trip_joins')
            ->withTimestamps()
            ->withPivot('status');
    }

    public function favoriteTrips(): BelongsToMany
    {
        return $this->belongsToMany(Trip::class, 'favorites')->withTimestamps();
    }

    public function recentTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'user_id')->latest('start_date');
    }

    // --- Follow System Relationships ---

    public function following(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'follower_id', 'following_id')->withTimestamps();
    }

    public function followers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'follows', 'following_id', 'follower_id')->withTimestamps();
    }

    public function isFollowedBy(?int $userId): bool
    {
        if (!$userId || $userId == $this->id) {
            return false;
        }
        return $this->followers()->where('follower_id', $userId)->exists();
    }

    // --- Posts Relationship ---

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    // --- NEW: Report & Block Relationships ---

    /**
     * Reports jo is user ne kiye hain (As Reporter)
     */
    public function givenReports(): HasMany
    {
        return $this->hasMany(UserReport::class, 'reporter_id');
    }

    /**
     * Reports jo is user par aaye hain (As Reported)
     */
    public function receivedReports(): HasMany
    {
        return $this->hasMany(UserReport::class, 'reported_id');
    }

    /**
     * Users jinhein is user ne block kiya hai
     */
    public function blockedUsers(): HasMany
    {
        return $this->hasMany(BlockedUser::class, 'blocker_id');
    }

    /**
     * Users jinhone is user ko block kiya hai
     */
    public function blockedBy(): HasMany
    {
        return $this->hasMany(BlockedUser::class, 'blocked_id');
    }

    /**
     * Helper: Check karein ke current user ne target user ko block kiya hai ya nahi
     */
    public function hasBlocked(int $targetUserId): bool
    {
        return $this->blockedUsers()->where('blocked_id', $targetUserId)->exists();
    }

    /**
     * Helper: Check karein ke target user ne current user ko block kiya hai ya nahi
     */
    public function isBlockedBy(int $targetUserId): bool
    {
        return $this->blockedBy()->where('blocker_id', $targetUserId)->exists();
    }

    // --- Profile Reviews Relationships ---

    public function givenProfileReviews()
    {
        return $this->hasMany(ProfileReview::class, 'reviewer_id');
    }

    public function receivedProfileReviews()
    {
        return $this->hasMany(ProfileReview::class, 'reviewee_id')
                    ->with('reviewer')
                    ->latest('created_at');
    }

    public function hasReviewedProfile(User $user): bool
    {
        return $this->givenProfileReviews()->where('reviewee_id', $user->id)->exists();
    }

    // --- Attributes ---

    public function getTotalTripsAttribute(): int
    {
        return $this->joinedTrips()
            ->wherePivot('status', 'accepted')
            ->count();
    }
}