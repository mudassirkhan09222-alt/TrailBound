<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Follow extends Model
{
    protected $fillable = [
        'follower_id',
        'following_id',
    ];

    // Relation: Follower (Jo follow kar raha hai)
    public function follower(): BelongsTo
    {
        return $this->belongsTo(User::class, 'follower_id');
    }

    // Relation: Following (Jise follow kiya gaya)
    public function following(): BelongsTo
    {
        return $this->belongsTo(User::class, 'following_id');
    }
}