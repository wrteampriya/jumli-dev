<?php

namespace App\Models;

use App\Models\Concerns\BelongsToModule;
use App\Services\FileService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class BannerAd extends Model
{
    use BelongsToModule, HasFactory;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $table = 'banner_ads';

    protected $fillable = [
        'title',
        'module_id',
        'platform',
        'page',
        'layout',
        'group_id',
        'position',
        'image',
        'ad_type',
        'link',
        'category_id',
        'advertisement_id',
        'section_key',
        'home_screen_section_id',
        'feature_section_id',
        'detail_page_section',
        'listing_page_section',
        'placement',
        'status',
    ];

    // module_id hidden from serialization — see Category.php's $hidden for why.
    protected $hidden = ['module_id'];

    protected $casts = [
        'status' => 'boolean',
        'position' => 'integer',
    ];

    /*
    |----------------------------------------------------------------------
    | Booted
    |----------------------------------------------------------------------
    */

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            if ($model->image) {
                FileService::delete($model->getRawOriginal('image'));
            }
        });
    }

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getImageAttribute($value)
    {
        return !empty($value) ? url(Storage::url($value)) : $value;
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function advertisement()
    {
        return $this->belongsTo(Item::class, 'advertisement_id');
    }

    public function featureSection()
    {
        return $this->belongsTo(FeatureSection::class, 'feature_section_id');
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeGroup($query, string $groupId)
    {
        return $query->where('group_id', $groupId);
    }

    public function scopeSearch($query, $search)
    {
        $search = '%' . $search . '%';
        return $query->where(function ($q) use ($search) {
            $q->whereLike('title', $search)
                ->orWhereLike('platform', $search)
                ->orWhereLike('page', $search)
                ->orWhereLike('layout', $search)
                ->orWhereLike('ad_type', $search);
        });
    }
}
