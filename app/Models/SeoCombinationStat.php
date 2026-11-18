<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SeoCombinationStat extends Model
{
    protected $table = 'seo_combination_stats';

    protected $fillable = [
        'country_id',
        'location_type',
        'location_id',
        'category_id',
        'locale',
        'url_path',
        'listing_count',
        'min_price',
        'avg_price',
        'depth',
        'in_sitemap',
        'last_calculated_at',
    ];

    protected $casts = [
        'country_id' => 'integer',
        'location_id' => 'integer',
        'category_id' => 'integer',
        'listing_count' => 'integer',
        'min_price' => 'decimal:2',
        'avg_price' => 'decimal:2',
        'depth' => 'integer',
        'in_sitemap' => 'boolean',
        'last_calculated_at' => 'datetime',
    ];

    public function country()
    {
        return $this->belongsTo(Country::class);
    }

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function city()
    {
        return $this->belongsTo(City::class, 'location_id');
    }

    public function state()
    {
        return $this->belongsTo(State::class, 'location_id');
    }
}
