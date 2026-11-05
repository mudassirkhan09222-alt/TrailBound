<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class PostMedia extends Model
{
    protected $fillable = [
        'post_id',
        'media_path',
        'type',
    ];

    protected $appends = ['media_url'];

    public function getMediaUrlAttribute(): ?string
    {
        if (!$this->media_path) {
            return null;
        }

        if (filter_var($this->media_path, FILTER_VALIDATE_URL)) {
            return $this->media_path;
        }

        return Storage::disk('public')->url($this->media_path);
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
