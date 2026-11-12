<?php

namespace App\Models;

use App\Services\FileService;
use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class Item extends Model
{
    use HasFactory, SoftDeletes, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'category_id',
        'currency_id',
        'name',
        'price',
        'pricing_mode',
        'description',
        'latitude',
        'longitude',
        'address',
        'contact',
        'country_code',
        'show_only_to_premium',
        'show_mobile_number',
        'video_link',
        'status',
        'rejected_reason',
        'user_id',
        'country',
        'state',
        'city',
        'area_id',
        'all_category_ids',
        'slug',
        'sold_to',
        'expiry_date',
        // Also the price range for pricing_mode = 'range' (any category,
        // not only jobs) — there are no separate min_price/max_price columns.
        'min_salary',
        'max_salary',
        'is_edited_by_admin',
        'admin_edit_reason',
        'package_id',
        'region_code',
        'created_at',
        'country_code',
        'item_type',
        'published_at',
        'renewed_at',
    ];

    protected $appends = ['translated_name', 'translated_description', 'image'];

    protected $with = ['translations'];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'show_mobile_number' => 'integer',
            'show_only_to_premium' => 'integer',
            'is_edited_by_admin' => 'integer',
            'is_dummy' => 'integer',
            // double columns cast to float: PostgreSQL returns them as strings, MySQL as numbers
            'price' => 'float',
            'min_salary' => 'float',
            'max_salary' => 'float',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    /*
    |----------------------------------------------------------------------
    | Booted
    |----------------------------------------------------------------------
    */

    protected static function booted()
    {
        static::forceDeleting(function ($model) {
            // Offers and chats are removed by DB cascade, which skips model
            // events, so chat attachments must be cleaned up here.
            Chat::whereIn('item_offer_id', $model->item_offers()->select('id'))
                ->where(fn ($q) => $q->where('file', '!=', '')->orWhere('audio', '!=', ''))
                ->select('id', 'file', 'audio')
                ->chunkById(200, function ($chats) {
                    foreach ($chats as $chat) {
                        FileService::delete($chat->getRawOriginal('file'));
                        FileService::delete($chat->getRawOriginal('audio'));
                    }
                });
            if ($model->seoDetail) {
                $model->seoDetail->delete();
            }
            if($model->reel){
                $model->reel->delete();
            }
            // Custom field values and the product video go by DB cascade too: delete
            // their uploaded files (file custom fields, a "file" type product video).
            ItemCustomFieldValue::where('item_id', $model->id)
                ->whereHas('custom_field', fn ($q) => $q->where('type', 'fileinput'))
                ->get()
                ->each(function (ItemCustomFieldValue $value) {
                    $raw = $value->getRawOriginal('value');
                    $decoded = json_validate((string) $raw) ? json_decode($raw, true) : $raw;
                    FileService::delete(is_array($decoded) ? ($decoded[0] ?? null) : $decoded);
                });
            if ($model->itemVideo && $model->itemVideo->video_type === 'file') {
                FileService::delete($model->itemVideo->getRawOriginal('video_file'));
            }
        });
    }

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    // Accessors
    public function getImageAttribute($image)
    {
        if (empty($image) && $this->id) {
            if ($this->relationLoaded('gallery_images')) {
                $defaultImage = $this->gallery_images->where('is_default', 1)->first() ?? $this->gallery_images->first();
                $image = $defaultImage ? $defaultImage->getRawOriginal('image') : null;
            } else {
                $defaultImage = $this->gallery_images()->where('is_default', 1)->first() ?? $this->gallery_images()->first();
                $image = $defaultImage ? $defaultImage->getRawOriginal('image') : null;
            }
        }
        return ! empty($image) ? url(Storage::url($image)) : $image;
    }

    public function getStatusAttribute($value)
    {
        if ($this->deleted_at) {
            return 'inactive';
        }
        if ($this->expiry_date && $this->expiry_date < Carbon::now() && $value != 'sold out') {
            return 'expired';
        }

        return $value;
    }

    public function getPublishedAtAttribute($value)
    {
        if (is_null($value) && $this->getRawOriginal('status') === 'approved') {
            return $this->created_at;
        }

        return $value;
    }

    public function getTranslatedNameAttribute()
    {
        return $this->getTranslatedValue('name', $this->name);
    }

    public function getTranslatedDescriptionAttribute()
    {
        return $this->getTranslatedValue('description', $this->description);
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    // Relationships
    public function reel()
    {
        return $this->hasOne(Reel::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function countryRelation()
    {
        return $this->belongsTo(Country::class);
    }

    public function currency()
    {
        return $this->belongsTo(Currency::class, 'currency_id');
    }

    public function category()
    {
        return $this->hasOne(Category::class, 'id', 'category_id');
    }

    public function gallery_images()
    {
        return $this->hasMany(ItemImages::class)->orderBy('is_default', 'desc')->orderBy('id', 'asc');
    }

    public function custom_fields()
    {
        return $this->hasManyThrough(
            CustomField::class, CustomFieldCategory::class,
            'category_id', 'id', 'category_id', 'custom_field_id'
        );
    }

    public function item_custom_field_values()
    {
        return $this->hasMany(ItemCustomFieldValue::class, 'item_id');
    }

    public function featured_items()
    {
        return $this->hasMany(FeaturedItems::class)->onlyActive();
    }

    public function favourites()
    {
        return $this->hasMany(Favourite::class);
    }

    public function item_offers()
    {
        return $this->hasMany(ItemOffer::class);
    }

    public function user_reports()
    {
        return $this->hasMany(UserReports::class);
    }

    public function sliders(): MorphMany
    {
        return $this->morphMany(Slider::class, 'model');
    }

    public function area()
    {
        return $this->belongsTo(Area::class);
    }

    public function review()
    {
        return $this->hasMany(SellerRating::class);
    }

    public function job_applications()
    {
        return $this->hasMany(JobApplication::class);
    }

    public function itemVideo()
    {
        return $this->hasOne(ItemVideo::class);
    }

    public function translations()
    {
        return $this->morphMany(\App\Models\Translation::class, 'translatable');
    }

    public function seoDetail(): MorphOne
    {
        return $this->morphOne(\App\Models\SeoDetail::class, 'seoable');
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    // Scopes
    public function scopeSearch($query, $search)
    {
        $originalSearch = $search;
        // Split the search into individual words so that extra/odd whitespace in
        // stored values (e.g. "Orange  couch") still matches a "Orange couch" query.
        $words = preg_split('/\s+/', trim($search), -1, PREG_SPLIT_NO_EMPTY);

        return $query->where(function ($query) use ($words, $originalSearch) {
            foreach ($words as $word) {
                $searchParam = '%'.$word.'%';

                // Every word must match at least one of the searchable fields.
                $query->where(function ($q) use ($searchParam, $originalSearch) {
                    // id is bigint: comparing it with free text errors on PostgreSQL, so only match numeric searches
                    $q->when(ctype_digit((string) $originalSearch), fn ($q) => $q->orWhere('id', (int) $originalSearch))
                        ->orWhereLike('name', $searchParam)
                        ->orWhereHas('category', function ($q) use ($searchParam) {
                            $q->whereLike('name', $searchParam);
                        })->orWhereHas('user', function ($q) use ($searchParam) {
                            $q->whereLike('name', $searchParam);
                        })->orWhereHas('translations', function ($q) use ($searchParam) {
                            $q->whereLike('value', $searchParam);
                        });
                });
            }
        });
    }

    public function scopeOwner($query)
    {
        if (Auth::user()->hasRole('User')) {
            return $query->where('user_id', Auth::user()->id);
        }

        return $query;
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeNotOwner($query)
    {
        return $query->where('user_id', '!=', Auth::user()->id);
    }

    public function scopeSort($query, $column, $order)
    {
        if ($column == 'user_name') {
            return $query->leftJoin('users', 'users.id', '=', 'items.user_id')
                ->orderBy('users.name', $order)
                ->select('items.*');
        }

        return $query->orderBy($column, $order);
    }

    public function scopeFilter($query, $filterObject)
    {
        if (empty($filterObject)) {
            return $query;
        }

        foreach ($filterObject as $column => $value) {

            if ($column === 'category_id') {

                $categoryId = (int) $value;

                // Match the selected category and ALL its descendants (any depth),
                // so selecting a parent (or mid-level sub) shows items in that
                // category and every level beneath it.
                $category = Category::find($categoryId);
                $categoryIds = $category
                    ? $category->descendantsAndSelf()->pluck('id')->toArray()
                    : [$categoryId];

                $query->whereIn('category_id', $categoryIds);

                continue; // Skip to next filter

            }
            if ($column == 'status') {

                if ($value == 'inactive') {
                    $query->whereNotNull('deleted_at')
                        ->where(function ($q) {
                            $q->whereNull('expiry_date')
                                ->orWhere('expiry_date', '>=', Carbon::now());
                        });

                } elseif ($value == 'expired') {
                    $query->whereNotNull('expiry_date')
                        ->where('expiry_date', '<', Carbon::now())
                        ->where('status', '!=', 'sold out')
                        ->whereNull('deleted_at');

                } elseif ($value == 'sold out') {
                    $query->where('status', 'sold out')->whereNull('deleted_at');
                } else {
                    if (in_array($value, [
                        'review', 'approved', 'rejected',
                        'soft rejected',
                        'permanent rejected', 'resubmitted',
                    ])) {

                        $query->whereNull('deleted_at')
                            ->where(function ($q) {
                                $q->whereNull('expiry_date')
                                    ->orWhere('expiry_date', '>=', Carbon::now());
                            });
                    }

                    $query->where($column, $value);
                }

            } elseif ($column == 'featured_status') {

                if ($value == 'featured') {
                    $query->whereHas('featured_items');
                } elseif ($value == 'premium') {
                    $query->whereDoesntHave('featured_items');
                }

            } elseif ($column === 'item_type') {

                if (in_array($value, ['normal', 'reel'])) {
                    $query->where('item_type', $value);
                }

            } elseif ($column === 'deleted_user') {
                $query->whereHas('user', function ($q) {
                    $q->onlyTrashed();
                });

            } elseif (in_array($column, ['country', 'state', 'city'])) {

                $query->whereLike($column, '%'.$value.'%');

            } else {
                $query->where((string) $column, (string) $value);
            }
        }

        return $query;
    }

    public function scopeOnlyNonBlockedUsers($query)
    {
        $blocked_user_ids = BlockUser::where('user_id', Auth::user()->id)
            ->pluck('blocked_user_id');

        return $query->whereNotIn('user_id', $blocked_user_ids);
    }

    public function scopeGetNonExpiredItems($query)
    {
        return $query->where(function ($query) {
            $query->where('expiry_date', '>', date('Y-m-d'))->orWhereNull('expiry_date');
        });
    }

    public function scopeIsJobCategory($query, $isJob = 1)
    {
        return $query->whereHas('category', function ($q) use ($isJob) {
            $q->where('is_job_category', $isJob);
        });
    }
}
