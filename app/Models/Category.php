<?php

namespace App\Models;

use App\Models\Concerns\BelongsToModule;
use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Storage;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;
use App\Services\Module\ModuleService;

class Category extends Model
{
    use BelongsToModule, HasFactory, HasRecursiveRelationships, ManageTranslations;

    /*
    |----------------------------------------------------------------------
    | Properties & Casts
    |----------------------------------------------------------------------
    */

    protected $fillable = [
        'name',
        'module_id',
        'parent_category_id',
        'image',
        'slug',
        'path',
        'status',
        'description',
        'is_job_category',
        'is_featured',
        'is_indexable',
    ];

    protected $appends = ['translated_name'];

    protected $with = ['translations'];

    // Modules are purely a backend/filtering concern in 3.2.0 — hidden so a
    // real column added in Phase 1 doesn't silently appear as a new key in
    // every API/admin JSON response that does an unrestricted get()/toArray().
    // Still fully readable via $category->module_id; this only affects
    // serialization.
    protected $hidden = ['module_id'];

    protected function casts(): array
    {
        return [
            // boolean columns cast to integer: the API returns 1/0 on both MySQL and PostgreSQL
            'status' => 'integer',
            'is_featured' => 'integer',
            'is_job_category' => 'integer',
            'is_dummy' => 'integer',
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

            ModuleService::clearCategoryIds($model->module_id);
        });

        static::saving(function ($model) {
            // A category always belongs to its parent's module — this is
            // authoritative, not merely a default-if-null, so it overrides
            // whatever BelongsToModule's own saving hook (registered
            // earlier, during trait boot) may have just filled in.
            //
            // Guarded the same way as BelongsToModule's own hook: several
            // pre-3.2.0 migrations touch Category rows before the
            // migration that adds categories.module_id has run.
            if ($model->parent_category_id && \Illuminate\Support\Facades\Schema::hasColumn('categories', 'module_id')) {
                $parentModuleId = static::where('id', $model->parent_category_id)->value('module_id');

                if ($parentModuleId) {
                    $model->module_id = $parentModuleId;
                }
            }

            if (!empty($model->slug)) {
                $model->path = $model->buildPath();
            }
        });

        static::saved(function ($model) {
            // ModuleService::categoryIds() has its own short TTL,
            // but explicit invalidation keeps a brand-new category
            // immediately visible to module-scoped item queries rather than
            // waiting out the cache window.
            if ($model->wasRecentlyCreated || $model->wasChanged('module_id')) {
                ModuleService::clearCategoryIds($model->module_id);
                ModuleService::clearCategoryIds($model->getOriginal('module_id'));
            }

            // module_id is included here because a root category's module
            // can now change directly via the admin form (once count() > 1
            // — see CategoryController::update()); without this, a
            // subcategory's own module_id would only catch up the next time
            // that subcategory itself is saved, leaving it briefly
            // inconsistent with its (new) parent's module.
            if ($model->wasChanged(['slug', 'parent_category_id', 'module_id'])) {
                $model->subcategories()->get()->each(function ($child) use ($model) {
                    $child->module_id = $model->module_id;
                    $child->path = $child->buildPath();
                    $child->saveQuietly();
                    $child->cascadePathToDescendants();
                });
            }
        });
    }

    /*
    |----------------------------------------------------------------------
    | Accessors
    |----------------------------------------------------------------------
    */

    public function getImageAttribute($image)
    {
        if (! empty($image)) {
            return url(Storage::url($image));
        }

        return $image;
    }

    public function getAllItemsCountAttribute()
    {
        // Count items of current category (no translations involved)
        $totalItems = $this->items()
            ->where('status', 'approved')
            ->getNonExpiredItems()
            ->count();

        // Load subcategories WITHOUT translations (only here)
        $subcategories = $this->subcategories()
            ->without('translations')
            ->get();

        foreach ($subcategories as $subcategory) {
            $totalItems += $subcategory->all_items_count;
        }

        return $totalItems;
    }

    public function getTranslatedNameAttribute()
    {
        return $this->getTranslatedValue('name', $this->name);
    }

    public function getFullPathAttribute()
    {
        $names = [];
        $current = $this;
        $visited = [];

        while ($current) {
            if (in_array($current->id, $visited, true)) {
                break; // prevent loop
            }
            $visited[] = $current->id;

            $names[] = $current->name;
            $current = $current->parent;
        }

        return implode(' > ', array_reverse($names));
    }

    public function getItemsGroupedByStatusAttribute()
    {
        $counts = [];

        // Count items in this category
        $items = $this->items()->get();
        foreach ($items as $item) {
            $counts[$item->status] = ($counts[$item->status] ?? 0) + 1;
        }

        // Include subcategories recursively
        foreach ($this->subcategories as $subcategory) {
            $subCounts = $subcategory->items_grouped_by_status;
            foreach ($subCounts as $status => $count) {
                $counts[$status] = ($counts[$status] ?? 0) + $count;
            }
        }

        return $counts;
    }

    public function getOtherItemsCountAttribute()
    {
        $totalItems = $this->items()->where('status', '!=', 'approved')->count();
        foreach ($this->subcategories as $subcategory) {
            $totalItems += $subcategory->other_items_count;
        }

        return $totalItems;
    }

    /*
    |----------------------------------------------------------------------
    | Relationships
    |----------------------------------------------------------------------
    */

    public function subcategories()
    {
        return $this->hasMany(self::class, 'parent_category_id');
    }

    public function custom_fields()
    {
        return $this->hasMany(CustomFieldCategory::class);
    }

    public function items()
    {
        return $this->hasMany(Item::class);
    }

    public function packages(){
        return $this->hasMany(PackageCategory::class, 'category_id')->where('is_discontinued','!=',1);
    }

    public function approved_items()
    {
        return $this->hasMany(Item::class)->where('status', 'approved');
    }

    public function slider(): MorphOne
    {
        return $this->morphOne(Slider::class, 'model');
    }

    public function seoDetail(): MorphOne
    {
        return $this->morphOne(\App\Models\SeoDetail::class, 'seoable');
    }

    public function translations()
    {
        return $this->morphMany(\App\Models\Translation::class, 'translatable');
    }

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_category_id');
    }

    /*
    |----------------------------------------------------------------------
    | Scopes
    |----------------------------------------------------------------------
    */

    public function scopeSearch($query, $search)
    {
        $originalSearch = $search;
        $search = '%'.$search.'%';

        return $query->where(function ($q) use ($search, $originalSearch) {
            // id is bigint: comparing it with free text errors on PostgreSQL, so only match numeric searches
            $q->when(ctype_digit((string) $originalSearch), fn ($q) => $q->orWhere('id', (int) $originalSearch))
                ->orWhereLike('name', $search)
                ->orWhereHas('translations', function ($q) use ($search) {
                    $q->whereLike('value', $search);
                });
        });
    }

    /*
    |----------------------------------------------------------------------
    | Custom Methods
    |----------------------------------------------------------------------
    */

    public function getPathName()
    {
        return 'tree_path';
    }

    public function buildPath(): string
    {
        $parent = $this->parent_category_id ? static::find($this->parent_category_id) : null;

        // Root categories (no parent, or a parent with no path yet) prefix
        // with their module's path_prefix. Seeded 'ads' for classified, so
        // this is behaviour-identical to the old hardcoded '/ads' — see the
        // v3.2.0 plan's "categories.path /ads prefix" risk note. The
        // /ads -> /classified rename itself is on hold pending the web team.
        $prefix = ($parent && $parent->path) ? $parent->path : '/' . ltrim($this->module?->path_prefix ?? 'ads', '/');

        return rtrim($prefix, '/') . '/' . $this->slug;
    }

    // Despite the name, also cascades module_id — the two travel together
    // (a subcategory's path prefix and its module are both inherited from
    // its ancestor chain), and this is already the recursive descent used
    // whenever either needs to catch up.
    public function cascadePathToDescendants(): void
    {
        $this->subcategories()->get()->each(function ($child) {
            // saveQuietly() bypasses model events entirely, so the saved
            // hook's cache-clear never fires for these rows — do it here
            // instead, for both the module the child is leaving and the one
            // it's joining.
            if ($child->module_id !== $this->module_id) {
                ModuleService::clearCategoryIds($child->module_id);
                ModuleService::clearCategoryIds($this->module_id);
            }

            $child->module_id = $this->module_id;
            $child->path = $child->buildPath();
            $child->saveQuietly();
            $child->cascadePathToDescendants();
        });
    }

    public function getParentKeyName()
    {
        return 'parent_category_id';
    }
}
