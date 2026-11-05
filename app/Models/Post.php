<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Post extends Model
{
    protected $fillable = [
        'user_id',
        'title',
        'description',
        'category',
    ];

    // User ke sath relationship (Har post ek user ki hoti hai)
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Post Media ke sath relationship (Ek post mein kai media files ho sakti hain)
    public function media(): HasMany
    {
        return $this->hasMany(PostMedia::class);
    }
}