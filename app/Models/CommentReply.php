<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class CommentReply extends Model
{
    use HasFactory;

    protected $fillable = [
        'comment_id',
        'user_id',
        'reply',
    ];

    public function comment()
    {
        return $this->belongsTo(PostComment::class, 'comment_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}