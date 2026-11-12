<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CountryLocationIndexability extends Model
{
    use HasFactory;

    protected $table = 'country_location_indexability';

    protected $fillable = [
        'country_id',
        'level',
        'is_indexable',
    ];

    protected $casts = [
        'is_indexable' => 'boolean',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }
}
