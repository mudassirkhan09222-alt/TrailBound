<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\Review; // <--- Ye Import Add Karein

class Trail extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'location_name',
        'latitude',
        'longitude',
        'distance',
        'elevation',
        'difficulty',
        'peak_month',
        'description',
        'media_url', 
        'track_points',
        'type', // Agar type column hai toh yahan bhi add karein
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'distance' => 'float',
        'elevation' => 'float',
        'track_points' => 'array',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(TrailMedia::class)->orderBy('order', 'asc');
    }

    // --- YE NAYA FUNCTION ADD KAREIN ---
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }
}