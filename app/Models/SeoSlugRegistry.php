<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SeoSlugRegistry extends Model
{
    use HasFactory;

    protected $table = 'seo_slug_registry';

    protected $fillable = [
        'country_id',
        'locale',
        'slug',
        'entity_type',
        'entity_id',
        'level',
        'parent_id',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
