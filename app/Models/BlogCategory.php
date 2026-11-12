<?php

namespace App\Models;

use App\Models\Concerns\BelongsToModule;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class BlogCategory extends Model
{
    use BelongsToModule, HasFactory, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'module_id',
        'name',
        'slug',
        'is_active',
    ];

    // module_id hidden from serialization — see Category.php's $hidden for why.
    protected $hidden = ['module_id'];

    protected $appends = ['translated_name'];

    protected $with = ['translations'];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'is_active' => 'integer',
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Booted
    |----------------------------------------------------------------------
    */

    protected static function booted()
    {
        static::deleting(function ($model) {
            if ($model->seoDetail) {
                $model->seoDetail->delete();
            }
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

    public function blogs()
    {
        return $this->hasMany(Blog::class, 'category_id', 'id');
    }

    public function seoDetail(): MorphOne
    {
        return $this->morphOne(\App\Models\SeoDetail::class, 'seoable');
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

    public function scopeSearch($query, $search)
    {
        $originalSearch = $search;
        $search = '%' . $search . '%';
        return $query->where(function ($q) use ($search, $originalSearch) {
            // id is bigint: comparing it with free text errors on PostgreSQL, so only match numeric searches
            $q->when(ctype_digit((string) $originalSearch), fn ($q) => $q->orWhere('id', (int) $originalSearch))
                ->orWhereLike('name', $search)
                ->orWhereLike('slug', $search)
                ->orWhereHas('translations', function ($q) use ($search) {
                    $q->whereLike('value', $search);
                });
        });
    }

    public function scopeSort($query, $column, $order)
    {
        return $query->orderBy($column, $order);
    }
}
