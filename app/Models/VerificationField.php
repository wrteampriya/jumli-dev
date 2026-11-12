<?php

namespace App\Models;

use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

use Throwable;

class VerificationField extends Model
{
    use HasFactory, ManageTranslations, SoftDeletes;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'type',
        'status',
        'values',
        'min_length',
        'max_length',
        'is_required',
        'deleted_at'
    ];

    protected $hidden = ['created_at', 'updated_at'];

  protected $appends = ['translated_name', 'translated_value'];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'is_required' => 'integer',
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getValuesAttribute($value) {
        try {
            return array_values(json_decode($value, true, 512, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return $value;
        }
    }

    public function getTranslatedNameAttribute()
    {
        return $this->getTranslatedValue('name', $this->name);
    }

    public function getTranslatedValueAttribute()
    {
        return $this->getTranslatedValue('value', $this->values);
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function user() {
        return $this->belongsTo(User::class);
    }

    public function values()
    {
        return $this->hasMany(VerificationFieldValue::class);
    }

    public function verification_field_values()
    {
        return $this->hasMany(VerificationFieldValue::class, 'verification_field_id');
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
        return $query->where(function ($q) use ($search) {
            $q->orWhereLike('name', $search)
                ->orWhereLike('type', $search)
                ->orWhereLike('values', $search)
                ->orWhereLike('status', $search);
        });
    }
}
