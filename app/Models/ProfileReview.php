<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProfileReview extends Model
{
    use HasFactory;

    protected $fillable = [
        'reviewer_id',
        'reviewee_id',
        'rating',
        'comment',
    ];

    protected $casts = [
        'rating' => 'decimal:1',
    ];

    // Review dene wala user
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    // Profile owner jisko review mila
    public function reviewee()
    {
        return $this->belongsTo(User::class, 'reviewee_id');
    }
}