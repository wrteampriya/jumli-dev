<?php

namespace App\Models;

use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model {
    use HasFactory, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'country_id',
        'state_id',
        'city_id',
        'state_code',
        'latitude',
        'longitude',
        'status',
    ];

    protected $appends = ['translated_name'];

    protected $with = ['translations'];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'status' => 'integer',
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

    public function city() {
        return $this->belongsTo(City::class);
    }

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

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
                if ($column == "city.name") {
                    $query->whereHas('city', function ($query) use ($value) {
                        $query->where('city_id', $value);
                    });
                } elseif ($column == "state.name") {
                    $query->whereHas('state', function ($query) use ($value) {
                        $query->where('state_id', $value);
                    });
                } elseif ($column == "country.name") {
                    $query->whereHas('country', function ($query) use ($value) {
                        $query->where('country_id', $value);
                    });
                } else {
                    $query->where((string)$column, (string)$value);
                }
            }
        }
        return $query;

    }

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhereLike('id', $search)
                ->orWhereLike('name', $search)
                ->orWhereLike('city_id', $search)
                ->orWhereLike('state_id', $search)
                ->orWhereLike('state_code', $search)
                ->orWhereLike('country_id', $search)
                ->orWhereHas('country', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                })->orWhereHas('state', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                })->orWhereHas('city', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                });
        });
        return $query;
    }

    /**
     * Areas called $name (their own or a translated name, any case) in the given
     * city (and state, when known). A blank name matches nothing.
     */
    public function scopeMatchingName($query, ?string $name, ?string $city = null, ?string $state = null) {
        $name = trim((string) $name);
        if ($name === '') {
            return $query->whereRaw('1 = 0');
        }

        $named = fn (string $value) => fn ($q) => $q->whereLike('name', $value)
            ->orWhereHas('translations', fn ($t) => $t->where('key', 'name')->whereLike('value', $value));

        return $query->where($named($name))
            ->when(filled($city), fn ($q) => $q->whereHas('city', fn ($c) => $c->where($named(trim($city)))
                ->when(filled($state), fn ($c) => $c->whereHas('state', $named(trim($state))))));
    }
}
