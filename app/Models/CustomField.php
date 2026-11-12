<?php

namespace App\Models;

use App\Models\Concerns\BelongsToModule;
use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CustomField extends Model {
    use BelongsToModule, HasFactory, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'module_id',
        'type',
        'image',
        'required',
        'status',
        'values',
        'min_length',
        'max_length',
    ];

    // module_id hidden from serialization — see Category.php's $hidden for why.
    protected $hidden = ['created_at', 'updated_at', 'module_id'];

    protected $appends = ['translated_name', 'translated_value'];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'status' => 'integer',
            'required' => 'integer',
            'is_dummy' => 'integer',
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

    public function getImageAttribute($image) {
        if (!empty($image)) {
            return url(Storage::url($image));
        }
        return $image;
    }

    public function getTranslatedNameAttribute() {
        return $this->getTranslatedValue('name', $this->name);
    }

    public function getTranslatedValueAttribute() {
        return $this->getTranslatedValue('value', $this->values);
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function custom_field_category() {
        return $this->hasMany(CustomFieldCategory::class, 'custom_field_id');
    }

    public function translations() {
        return $this->morphMany(\App\Models\Translation::class, 'translatable');
    }

    public function item_custom_field_values() {
        return $this->hasMany(ItemCustomFieldValue::class);
    }

    public function categories() {
        return $this->belongsToMany(Category::class, CustomFieldCategory::class);
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeSearch($query, $search) {
        $search = "%" . $search . "%";
        $query = $query->where(function ($q) use ($search) {
            $q->orWhereLike('name', $search)
                ->orWhereLike('type', $search)
                ->orWhereLike('values', $search)
                ->orWhereLike('status', $search)
                ->orWhereHas('categories', function ($q) use ($search) {
                    $q->whereLike('name', $search);
                });
        });
        return $query;
    }

    public function scopeFilter($query, $filterObject) {
        if (!empty($filterObject)) {
            foreach ($filterObject as $column => $value) {
                if ($column == "category_names") {
                    $query->whereHas('custom_field_category', function ($query) use ($value) {
                        $query->where('category_id', $value);
                    });
                } elseif ($column == "type") {
                    $query->where('type', $value);
                } else {
                    $query->where((string)$column, (string)$value);
                }
            }
        }
        return $query;

    }
}
