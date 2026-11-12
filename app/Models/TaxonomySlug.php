<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TaxonomySlug extends Model
{
    use HasFactory;

    protected $table = 'taxonomy_slugs';

    protected $fillable = [
        'entity_type',
        'entity_id',
        'locale',
        'slug',
    ];
}
