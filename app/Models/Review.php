<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Review extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'trail_id',
        'rating',
        'comment',
    ];

    // Relationship: Ye review kis user ka hai
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Relationship: Ye review kis trail ka hai
    public function trail(): BelongsTo
    {
        return $this->belongsTo(Trail::class);
    }
}