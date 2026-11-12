<?php

namespace App\Models;

use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class State extends Model {
    use HasFactory, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        "id",
        "name",
        "state_code",
        "latitude",
        "longitude",
        "type",
        "country_id",
        "country_code",
        "fips_code",
        "iso2",
        "type",
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

    public function country() {
        return $this->belongsTo(Country::class);
    }

    public function cities() {
        return $this->hasMany(City::class);
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

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhereLike('id', $search)
                ->orWhereLike('name', $search)
                ->orWhereLike('country_id', $search)
                ->orWhereLike('state_code', $search)
                ->orWhereHas('country', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                });
        });
        return $query;
    }

    public function scopeSort($query, $column, $order) {
        if ($column == "country_name") {
            $query = $query->leftjoin('countries', 'countries.id', '=', 'states.country_id')->orderBy('countries.name', $order);
        } else {
            $query = $query->orderBy($column, $order);
        }

        return $query->select('states.*');
    }

    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
              if($column == "country_name") {
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

