<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Hiker extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'avatar',
        'bio',
        'tags',
        'trails_completed',
        'total_miles',
        'reviews_count',
    ];

    protected $casts = [
        'tags' => 'array',
        'total_miles' => 'decimal:2',
    ];

    public function recentTrips(): HasMany
    {
        return $this->hasMany(Trip::class, 'hiker_id')->latest('start_date');
    }
}