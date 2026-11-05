<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrailReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'trail_id',
        'condition',
        'description',
        'facilities',
        'confirmations',
    ];

    // Cast facilities to array so we can easily work with JSON
    protected $casts = [
        'facilities' => 'array',
        'confirmations' => 'integer',
    ];

    // Relationship: Ye report kis user ki hai
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Relationship: Ye report kis trail ki hai
    public function trail(): BelongsTo
    {
        return $this->belongsTo(Trail::class);
    }
}