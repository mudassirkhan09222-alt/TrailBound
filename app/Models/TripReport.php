<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'trip_id',
        'condition',
        'description',
        'issues',
        'confirmations',
    ];

    // Cast issues to array so we can easily work with JSON
    protected $casts = [
        'issues' => 'array',
        'confirmations' => 'integer',
    ];

    // Relationship: Ye report kis user ki hai
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Relationship: Ye report kis trip ki hai
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}