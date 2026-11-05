<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TripMedia extends Model
{
    use HasFactory;

    protected $table = 'trip_media'; // Explicitly setting table name since it's plural/special

    protected $fillable = [
        'trip_id',
        'url',
        'type',
        'order',
    ];

    /**
     * The trip that owns this media item.
     */
    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}