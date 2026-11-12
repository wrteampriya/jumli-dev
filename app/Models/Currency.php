<?php

namespace App\Models;

use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Currency extends Model
{
    use HasFactory, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'iso_code',
        'name',
        'symbol',
        'symbol_position',
        'decimal_places',
        'thousand_separator',
        'decimal_separator',
        'country_id',
    ];

    protected $appends = ['translated_name'];

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

    public function country()
    {
        return $this->belongsTo(Country::class, 'country_id', 'id');
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

    public function scopeSort($query, $sortBy, $order)
    {
        if ($sortBy === 'country_name' || $sortBy === 'country.name') {
            return $query
                ->leftJoin('countries', 'currencies.country_id', '=', 'countries.id')
                ->orderBy('countries.name', $order)
                ->select('currencies.*');
        }

        return $query->orderBy($sortBy, $order);
    }

    public function scopeSearch($query, $search)
    {
        $search = '%' . $search . '%';

        return $query->where(function ($q) use ($search) {
            $q->whereLike('currencies.id', $search)
                ->orWhereLike('currencies.iso_code', $search)
                ->orWhereLike('currencies.name', $search)
                ->orWhereLike('currencies.symbol', $search)
                ->orWhereHas('country', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                });
        });
    }
}
