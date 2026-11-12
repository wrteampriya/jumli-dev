<?php

namespace App\Models;

use App\Services\License\LicenseDomain;
use App\Services\Module\ModuleService;
use App\Traits\ManageTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class Module extends Model
{
    use HasFactory, ManageTranslations;

    protected $fillable = [
        'slug',
        'name',
        'description',
        'icon',
        'path_prefix',
        'status',
        'sequence',
        'version',
        'plugin_slug',
    ];

    protected $casts = [
        'status' => 'boolean',
    ];

    protected static function booted(): void
    {
        // At least one module must stay active: the last active one can't be
        // switched off or deleted. Controller::changeStatus() writes through
        // DB::table() (no Eloquent events), so it repeats the status guard.
        static::deleting(function (self $module) {
            if ($module->status && $module->isLastActive()) {
                throw new RuntimeException('At least one module must stay active.');
            }

            // Its home screen section rows are module-owned settings (created
            // with the module) — remove them so the module_id FK doesn't block.
            $module->homeScreenSections()->delete();
        });

        // A new module starts with every home screen section, so the admin
        // can toggle them and the app gets sections for its X-Module.
        static::created(function (self $module) {
            HomeScreenSection::ensureForModule($module->id);
        });

        static::updating(function (self $module) {
            if ($module->isDirty('slug')) {
                throw new RuntimeException("A module's slug is immutable.");
            }

            if ($module->isDirty('status') && !$module->status && $module->isLastActive()) {
                throw new RuntimeException('At least one module must stay active.');
            }
        });
    }

    // `icon` holds an uploaded file's storage path, served as a full URL.
    public function getIconAttribute($icon)
    {
        if (!empty($icon)) {
            return url(Storage::url($icon));
        }

        return $icon;
    }

    public function translations()
    {
        return $this->morphMany(Translation::class, 'translatable');
    }

    // The module's own values only — a definition with no row here resolves
    // to its default_value.
    public function settingValues()
    {
        return $this->hasMany(ModuleSettingValue::class);
    }

    public function categories()
    {
        return $this->hasMany(Category::class);
    }

    public function rootCategories()
    {
        return $this->hasMany(Category::class)->whereNull('parent_category_id');
    }

    public function customFields()
    {
        return $this->hasMany(CustomField::class);
    }

    public function packages()
    {
        return $this->hasMany(Package::class)->where('is_discontinued', '!=', 1);
    }

    public function sliders()
    {
        return $this->hasMany(Slider::class);
    }

    public function bannerAds()
    {
        return $this->hasMany(BannerAd::class);
    }

    public function featureSections()
    {
        return $this->hasMany(FeatureSection::class);
    }

    public function homeScreenSections()
    {
        return $this->hasMany(HomeScreenSection::class);
    }

    public function tips()
    {
        return $this->hasMany(Tip::class);
    }

    public function notifications()
    {
        return $this->hasMany(Notifications::class);
    }

    // Listings reach their module through the category, not a column of
    // their own — see the plan's "items performance" note for why.
    public function items()
    {
        return $this->hasManyThrough(Item::class, Category::class, 'module_id', 'category_id');
    }

    public function license()
    {
        return $this->hasOne(PluginLicense::class, 'plugin_slug', 'plugin_slug')
            ->whereIn('domain', LicenseDomain::equivalents());
    }

    // Active = switched on AND, for a plugin-installed module, its license is
    // enabled and not revoked — so disabling the plugin hides the module from
    // X-Module, get-modules and every admin module picker without touching
    // its data. Rows with no plugin_slug (classified, hand-added) need no license.
    public function scopeActive($query)
    {
        return $query->where($query->qualifyColumn('status'), true)
            ->where(function ($query) {
                $query->whereNull($query->qualifyColumn('plugin_slug'))
                    ->orWhereIn($query->qualifyColumn('plugin_slug'), PluginLicense::select('plugin_slug')
                        ->where('is_enabled', true)
                        ->where('revoked', false));
            });
    }

    // At least one module must stay active — the apps need a module to open on.
    public function isLastActive(): bool
    {
        return static::query()->active()->whereKeyNot($this->getKey())->doesntExist();
    }

    // The module an admin create form starts on.
    public function isDefault(): bool
    {
        return $this->id === ModuleService::defaultModuleId();
    }

    // Mirrors PluginLicense::isUsable() — an addon whose license is revoked
    // goes dark without any data being deleted.
    public function isUsable(): bool
    {
        if (!$this->status) {
            return false;
        }

        if ($this->plugin_slug === null) {
            return true;
        }

        return (bool) $this->license?->isUsable();
    }
}
