<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SupportTicket extends Model
{
    protected $fillable = [
        'name',
        'email',
        'concern',
        'message',
        'attachment_path',
        'user_id',
        'status',
    ];
}