<?php

namespace App\Models;

use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasFactory, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'id',
        'name',
        'iso3',
        'numeric_code',
        'iso2',
        'phonecode',
        'capital',
        'currency',
        'currency_name',
        'currency_symbol',
        'tld',
        'native',
        'region',
        'region_id',
        'subregion',
        'subregion_id',
        'nationality',
        'timezones',
        'latitude',
        'longitude',
        'emoji',
        'emojiU',
        'created_at',
        'updated_at',
        'flag',
        'wikiDataId',
        'status',
        'seo_slug',
        'default_locale',
        'currency_code',
        'is_default',
    ];

    protected $appends = ['translated_name'];

    protected $with = ['translations'];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'status' => 'integer',
            'flag' => 'integer',
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getTranslatedNameAttribute()
    {
        return $this->getTranslatedValue('name', $this->name);
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function states()
    {
        return $this->hasMany(State::class);
    }

    public function translations()
    {
        return $this->morphMany(\App\Models\Translation::class, 'translatable');
    }

    public function currency()
    {
        return $this->hasOne(Currency::class);
    }

    public function locationIndexability()
    {
        return $this->hasMany(CountryLocationIndexability::class);
    }

    public function slugRegistry()
    {
        return $this->hasMany(SeoSlugRegistry::class);
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeSearch($query, $search)
    {
        $search = '%'.$search.'%';
        $query = $query->where(function ($q) use ($search) {
            $q->orWhereLike('id', $search)
                ->orWhereLike('name', $search)
                ->orWhereLike('numeric_code', $search)
                ->orWhereLike('phonecode', $search);
        });

        return $query;
    }
}
