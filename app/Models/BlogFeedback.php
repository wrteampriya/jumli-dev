<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BlogFeedback extends Model {
    use HasFactory;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $table = 'blog_feedbacks';

    protected $fillable = [
        'blog_id',
        'user_id',
        'is_useful'
    ];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'is_useful' => 'integer',
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function blog() {
        return $this->belongsTo(Blog::class, 'blog_id');
    }

    public function user() {
        return $this->belongsTo(User::class, 'user_id');
    }
}
