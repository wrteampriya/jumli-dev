<?php

namespace App\Models;

use App\Models\Concerns\BelongsToModule;
use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FeatureSection extends Model {
    use BelongsToModule, HasFactory, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'title',
        'module_id',
        'slug',
        'sequence',
        'filter',
        'value',
        'style',
        'min_price',
        'max_price',
        'description',
        'status',
        'items_limit',
        'promotion_tier',
    ];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'items_limit' => 'integer',
        ];
    }

      protected $appends = ['translated_name', 'values_text'];

    // module_id hidden from serialization — see Category.php's $hidden for why.
    protected $hidden = ['module_id'];

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getValuesTextAttribute(): string {
        return match ($this->filter) {
            'price_criteria'    => 'Min: ' . ($this->min_price ?? 0) . ', Max: ' . ($this->max_price ?? 0),
            'category_criteria',
            'item_selection'    => $this->value ?? '',
            default             => '',
        };
    }

    public function getTranslatedNameAttribute() {
        return $this->getTranslatedValue('name', $this->title);
    }

    public function getTranslatedDescriptionAttribute() {
        return $this->getTranslatedValue('description', $this->description);
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function category() {
        return $this->belongsTo(Category::class, 'category_id', 'id');
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
            $q->orWhereLike('title', $search)
                ->orWhereLike('sequence', $search)
                ->orWhereLike('filter', $search)
                ->orWhereLike('value', $search)
                ->orWhereLike('style', $search)
                ->orWhereLike('min_price', $search)
                ->orWhereLike('max_price', $search)
                ->orWhereLike('created_at', $search)
                ->orWhereLike('updated_at', $search)
                ->orWhereLike('description', $search);
        });
        return $query;
    }
}
