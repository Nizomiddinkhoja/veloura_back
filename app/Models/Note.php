<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Note extends Model
{
    use HasFactory;

    protected $fillable = [
        'text',
        'is_deleted',
    ];

    protected $casts = [
        'text' => 'string',
        'is_deleted' => 'boolean',
    ];

    protected $hidden = ['is_deleted'];

}
