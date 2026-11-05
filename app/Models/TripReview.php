<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'trip_id',
        'rating',
        'comment',
    ];

    // Relationship: Ye review kis user ka hai
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Relationship: Ye review kis trip ka hai
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}