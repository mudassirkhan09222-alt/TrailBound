<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class PostComment extends Model
{
    use HasFactory;

    protected $fillable = [
        'post_id',
        'user_id',
        'comment',
    ];

    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function replies()
    {
        return $this->hasMany(CommentReply::class, 'comment_id');
    }

    public function likes()
    {
        return $this->hasMany(CommentLike::class, 'comment_id');
    }
}