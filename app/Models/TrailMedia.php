<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TrailMedia extends Model
{
    use HasFactory;

    protected $fillable = [
        'trail_id',
        'url',
        'type',
        'order',
    ];

    public function trail(): BelongsTo
    {
        return $this->belongsTo(Trail::class);
    }
}