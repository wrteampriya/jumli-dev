<?php

namespace App\Models;

use App\Traits\ManageTranslations;
use App\Services\SeoTaxonomyService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model {
    use HasFactory, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        "id",
        "name",
        "state_id",
        "state_code",
        "country_id",
        "country_code",
        "latitude",
        "longitude",
        "created_at",
        "updated_at",
        "flag",
        "wikiDataId",
        "status",
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
    | Booted
    |----------------------------------------------------------------------
    */

    protected static function booted()
    {
        static::saved(function ($model) {
            SeoTaxonomyService::syncCity($model);
        });

        static::deleting(function ($model) {
            SeoTaxonomyService::deleteCity($model->id);
        });
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

    public function state() {
        return $this->belongsTo(State::class);
    }

    public function country() {
        return $this->belongsTo(Country::class);
    }

    public function translations()
    {
        return $this->morphMany(\App\Models\Translation::class, 'translatable');
    }

    public function areas(): HasMany
    {
        return $this->hasMany(Area::class);
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeSearch($query, $search) {
    $search = "%" . $search . "%";

    $query = $query->where(function ($q) use ($search) {
        $q->orWhereLike('cities.id', $search)
            ->orWhereLike('cities.name', $search)
            ->orWhereLike('cities.state_id', $search)
            ->orWhereLike('cities.state_code', $search)
            ->orWhereLike('cities.country_id', $search)
            ->orWhereLike('cities.country_code', $search)
            ->orWhereHas('state', function ($q) use ($search) {
                $q->whereLike('states.name', $search);
            })
            ->orWhereHas('country', function ($q) use ($search) {
                $q->whereLike('countries.name', $search);
            });
    });
    return $query;
}

   public function scopeSort($query, $column, $order) {
    if ($column == "country_name") {
        $query = $query->leftJoin('countries', 'countries.id', '=', 'cities.country_id')
                       ->orderBy('countries.name', $order);
    } elseif ($column == "state_name") {
        $query = $query->leftJoin('states', 'states.id', '=', 'cities.state_id')
                       ->orderBy('states.name', $order);
    } else {
        $query = $query->orderBy("cities.$column", $order);
    }
    return $query->select('cities.*');
}

    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
                if($column == "state_name") {
                    $query->whereHas('state', function ($query) use ($value) {
                        $query->where('state_id', $value);
                    });
                }
                elseif($column == "country_name") {
                    $query->whereHas('country', function ($query) use ($value) {
                        $query->where('country_id', $value);
                    });
                }
                else {
                    $query->where((string)$column, (string)$value);
                }
            }
        }
        return $query;

    }
}
