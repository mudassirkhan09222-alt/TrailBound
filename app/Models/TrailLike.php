<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrailLike extends Model
{
    protected $fillable = ['user_id', 'trail_id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trail(): BelongsTo
    {
        return $this->belongsTo(Trail::class);
    }
}