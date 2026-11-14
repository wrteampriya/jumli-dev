<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoRedirect extends Model
{
    use HasFactory;

    protected $table = 'seo_redirects';

    protected $fillable = [
        'source_path',
        'target_path',
        'status_code',
        'redirect_type',
        'is_active',
        'hits',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'hits' => 'integer',
        'status_code' => 'integer',
    ];
}
