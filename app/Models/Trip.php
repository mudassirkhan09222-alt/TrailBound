<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Trip extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'trail_id',
        'title',
        'description',
        'start_date',
        'end_date',
        'group_capacity',
        'pace',
        'media_url',
        'tags',
        'itinerary',
        'is_completed',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'group_capacity' => 'integer',
        'tags' => 'array',
        'is_completed' => 'boolean',
    ];

        // Trail relationship
    public function trail(): BelongsTo
    {
        return $this->belongsTo(Trail::class);
    }

    // Trip create karne wala host
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Trip join karne wale users (Direct User objects)
public function members(): BelongsToMany
{
    return $this->belongsToMany(User::class, 'trip_joins', 'trip_id', 'user_id')
        ->withPivot('status')
        ->withTimestamps();
}
    // Trip ki images aur videos
    public function media(): HasMany
    {
        return $this->hasMany(TripMedia::class)
            ->orderBy('order', 'asc');
    }

    // Available spots
    public function getSpotsLeftAttribute(): int
    {
        $joinedCount = $this->members()
            ->wherePivot('status', 'accepted')
            ->count();

        return max(0, $this->group_capacity - $joinedCount);
    }

    // Trip ki date range
    public function getDateRangeAttribute(): string
    {
        if (!$this->start_date || !$this->end_date) {
            return '';
        }

        $startDate = \Carbon\Carbon::parse($this->start_date);
        $endDate = \Carbon\Carbon::parse($this->end_date);

        if (
            $startDate->month === $endDate->month &&
            $startDate->year === $endDate->year
        ) {
            return sprintf(
                '%s %d - %d',
                $startDate->format('M'),
                $startDate->day,
                $endDate->day
            );
        }

        return sprintf(
            '%s %d - %s %d',
            $startDate->format('M'),
            $startDate->day,
            $endDate->format('M'),
            $endDate->day
        );
    }

    // Check karein ke trip full hai ya nahi
    public function isFull(): bool
    {
        return $this->spots_left <= 0;
    }
}
