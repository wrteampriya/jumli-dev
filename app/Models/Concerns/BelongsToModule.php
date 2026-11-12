<?php

namespace App\Models\Concerns;

use App\Models\Module;
use Illuminate\Support\Facades\Schema;
use App\Services\Module\ModuleService;

// Applied to every table that owns a module_id column (see the v3.2.0 plan's
// "module_id placement" section — categories, custom_fields, packages,
// sliders, banner_ads, feature_sections, home_screen_sections, tips,
// notifications). Items do NOT use this trait — they reach their module via
// category_id, never a column of their own.
trait BelongsToModule
{
    public static function bootBelongsToModule(): void
    {
        // 'saving' (not 'creating'): traits boot before a model's own
        // booted() runs, so this listener registers — and therefore fires —
        // before any model-specific 'saving' hook such as
        // Category::booted()'s path builder, which needs module_id already
        // resolved (directly, or via that same hook's parent-inheritance
        // override) before it can pick the right path_prefix.
        static::saving(function ($model) {
            if (!is_null($model->module_id)) {
                return;
            }

            // Guards a real fresh-install failure: several pre-3.2.0
            // migrations seed rows on these models (e.g. v2.13.0 seeds a
            // HomeScreenSection) that run BEFORE the v3.2.0 migration that
            // creates the modules table — ModuleService::defaultModule() would query
            // a table that doesn't exist yet and abort the whole install.
            // Same reasoning for the module_id column itself: on some of
            // these tables it's added by the same later migration.
            if (!Schema::hasTable('modules') || !Schema::hasColumn($model->getTable(), 'module_id')) {
                return;
            }

            $model->module_id = ModuleService::currentId() ?? ModuleService::defaultModule()?->id;
        });
    }

    public function module()
    {
        return $this->belongsTo(Module::class);
    }

    // Admin lists pass their optional Module filter, API endpoints pass
    // currentModuleId() (the X-Module header). null = all modules.
    public function scopeForModule($query, ?int $moduleId = null)
    {
        return $query->when($moduleId, fn ($q) => $q->where($q->qualifyColumn('module_id'), $moduleId));
    }
}
