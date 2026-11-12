<?php

namespace App\Services\Module;

use App\Models\Category;
use App\Models\Item;
use App\Models\Module;
use App\Services\CachingService;
use Illuminate\Support\Facades\DB;

/**
 * Everything about modules that isn't the Module model itself:
 *
 * - current / default module (X-Module header, fallback for new rows)
 * - which module a category / item belongs to, and the ownership check on save
 * - per-module settings (definitions + values) and their cache
 * - the Modules page card counts
 *
 * Every scoped row has a NOT NULL module_id; items reach their module through their category.
 */
class ModuleService
{
    protected const CATEGORY_IDS_TTL = 300;

    protected const STATS_CACHE_KEY = 'module_stats';

    protected const STATS_TTL = 300;

    /*
    |--------------------------------------------------------------------------
    | Current / default module
    |--------------------------------------------------------------------------
    */

    // The module the API request asked for via X-Module (set by ResolveApiModule),
    // or null = no module filter. Admin requests never set it.
    public static function currentId(): ?int
    {
        return app()->bound('request') ? request()->attributes->get('module_id') : null;
    }

    // The module apps open on and new rows fall back to: the first active module by sequence.
    public static function defaultModule(): ?Module
    {
        return Module::active()->orderBy('sequence')->orderBy('id')->first();
    }

    // defaultModule()'s id, once per request — for admin form preselects. Not for
    // save hooks, which can run in a long-lived worker where a memoised value goes stale.
    public static function defaultModuleId(): ?int
    {
        return once(fn () => static::defaultModule()?->id);
    }

    /*
    |--------------------------------------------------------------------------
    | Ownership
    |--------------------------------------------------------------------------
    */

    public static function moduleIdForCategory(?int $categoryId): ?int
    {
        return $categoryId ? Category::whereKey($categoryId)->value('module_id') : null;
    }

    public static function moduleIdForItem(?int $itemId): ?int
    {
        if (!$itemId) {
            return null;
        }

        return Item::join('categories', 'categories.id', '=', 'items.category_id')
            ->where('items.id', $itemId)
            ->value('categories.module_id');
    }

    // A module's category ids — the way to filter items by module
    // (whereIn category_id, never whereHas). Cleared from Category events.
    public static function categoryIds(int $moduleId): array
    {
        return CachingService::cacheRemember(
            "category_ids_for_module_{$moduleId}",
            fn () => Category::where('module_id', $moduleId)->pluck('id')->all(),
            self::CATEGORY_IDS_TTL
        );
    }

    public static function clearCategoryIds(?int $moduleId): void
    {
        if ($moduleId) {
            CachingService::removeCache("category_ids_for_module_{$moduleId}");
        }
    }

    // Admin save guard for records that point at categories / items (sliders, banner ads,
    // feature sections). Returns a translated error, or null when every id belongs to
    // $moduleId (null = the default module, same fallback as the save hook).
    public static function ownershipError(?int $moduleId, iterable $categoryIds = [], iterable $itemIds = []): ?string
    {
        $categoryIds = collect($categoryIds)->filter()->map(fn ($id) => (int) $id)->unique();
        $itemIds = collect($itemIds)->filter()->map(fn ($id) => (int) $id)->unique();

        $moduleId ??= static::defaultModuleId();
        if (!$moduleId || ($categoryIds->isEmpty() && $itemIds->isEmpty())) {
            return null;
        }

        $moduleCategoryIds = static::categoryIds($moduleId);

        if ($categoryIds->diff($moduleCategoryIds)->isNotEmpty()) {
            return __('The selected category does not belong to the selected module.');
        }

        if ($itemIds->isNotEmpty()
            && Item::whereIn('id', $itemIds)->whereNotIn('category_id', $moduleCategoryIds)->exists()) {
            return __('The selected item does not belong to the selected module.');
        }

        return null;
    }

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */

    // A module's resolved settings: its own value, else the definition's default.
    // A null module (or a key with no definition) gets the fallback below.
    public static function settings(?int $moduleId): array
    {
        $rows = $moduleId ? static::settingRows($moduleId) : collect();

        $value = function (string $name, mixed $fallback) use ($rows) {
            $row = $rows->get($name);
            if (!$row) {
                return $fallback;
            }
            $raw = $row->value_id ? $row->value : $row->default_value;

            return match ($row->type) {
                'boolean' => (bool) $raw,
                'integer' => (int) $raw,
                'array' => ($raw === null || $raw === '') ? [] : explode(',', $raw),
                default => $raw,
            };
        };

        return [
            'pricing_modes' => $value('pricing_modes', []),
            'default_pricing_mode' => $value('default_pricing_mode', 'fixed'),
            'ad_packages_enabled' => $value('ad_packages_enabled', true),
            'feature_packages_enabled' => $value('feature_packages_enabled', true),
            'max_images' => (int) $value('max_images', 5),
            'product_video' => (bool) $value('product_video', true),
            'video_max_file_size_mb' => (int) $value('video_max_file_size_mb', 50),
        ];
    }

    // Pricing modes a module can offer => translated label, in the order of the
    // pricing_modes definition's options (the single list every form reads).
    public static function pricingModes(): array
    {
        $definition = collect(static::settingDefinitions())->firstWhere('name', 'pricing_modes');
        $labels = [
            'free' => __('Free'),
            'fixed' => __('Fixed'),
            'range' => __('Range'),
            'negotiable' => __('Negotiable'),
            'price_on_request' => __('Price on Request'),
        ];

        return collect(json_decode($definition['options'], true))
            ->mapWithKeys(fn (string $mode) => [$mode => $labels[$mode] ?? ucwords(str_replace('_', ' ', $mode))])
            ->all();
    }

    public static function setting(?int $moduleId, string $name): mixed
    {
        return static::settings($moduleId)[$name] ?? null;
    }

    // Category toggles the module's pricing modes allow: Job Category needs Range
    // (a salary range reuses its min/max inputs).
    public static function categoryToggles(?int $moduleId): array
    {
        return [
            'is_job_category' => in_array('range', static::setting($moduleId, 'pricing_modes'), true),
        ];
    }

    public static function clearSettingsCache(int $moduleId): void
    {
        CachingService::removeCache("module_settings_{$moduleId}");
    }

    protected static function settingRows(int $moduleId)
    {
        return CachingService::cacheRemember(
            "module_settings_{$moduleId}",
            fn () => DB::table('module_setting_definitions as d')
                ->leftJoin('module_setting_values as v', function ($join) use ($moduleId) {
                    $join->on('v.module_setting_definition_id', '=', 'd.id')
                        ->where('v.module_id', $moduleId);
                })
                ->select('d.name', 'd.type', 'd.default_value', 'v.id as value_id', 'v.value')
                ->get()
                ->keyBy('name')
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Setting definitions
    |--------------------------------------------------------------------------
    */

    // One module_setting_definitions row per key, shared by every module. Upserted by
    // name from the v3.2.0 migration and SystemUpgradeSeeder, so a new key reaches
    // existing installs through the self-updater.
    public static function settingDefinitions(): array
    {
        $definitions = [
            [
                'name' => 'pricing_modes',
                'type' => 'array',
                'default_value' => 'fixed,negotiable',
                'options' => ['free', 'fixed', 'range', 'negotiable', 'price_on_request'],
                'rules' => null,
                'is_required' => true,
                'section' => 'pricing',
                'label' => 'Supported pricing',
                'help_text' => null,
            ],
            [
                'name' => 'default_pricing_mode',
                'type' => 'string',
                'default_value' => 'fixed',
                'options' => ['free', 'fixed', 'range', 'negotiable', 'price_on_request'],
                'rules' => null,
                'is_required' => false,
                'section' => 'pricing',
                'label' => 'Default',
                'help_text' => null,
            ],
            [
                'name' => 'ad_packages_enabled',
                'type' => 'boolean',
                'default_value' => '1',
                'options' => null,
                'rules' => null,
                'is_required' => false,
                'section' => 'monetization',
                'label' => 'Ad Packages (listing limits)',
                'help_text' => null,
            ],
            [
                'name' => 'feature_packages_enabled',
                'type' => 'boolean',
                'default_value' => '1',
                'options' => null,
                'rules' => null,
                'is_required' => false,
                'section' => 'monetization',
                'label' => 'Feature / Promote Packages',
                'help_text' => null,
            ],
            [
                'name' => 'max_images',
                'type' => 'integer',
                'default_value' => '5',
                'options' => null,
                'rules' => 'min:1|max:50',
                'is_required' => false,
                'section' => 'media',
                'label' => 'Total images limit',
                'help_text' => null,
            ],
            [
                'name' => 'video_max_file_size_mb',
                'type' => 'integer',
                'default_value' => '50',
                'options' => null,
                'rules' => 'min:1|max:500',
                'is_required' => false,
                'section' => 'media',
                'label' => 'Product video max size (MB)',
                'help_text' => null,
            ],
            [
                'name' => 'product_video',
                'type' => 'boolean',
                'default_value' => '1',
                'options' => null,
                'rules' => null,
                'is_required' => false,
                'section' => 'media',
                'label' => 'Product videos',
                'help_text' => null,
            ],
        ];

        // options is a json column; encoded here so the rows work with both
        // query-builder upserts and Eloquent.
        return array_map(function (array $definition) {
            $definition['options'] = $definition['options'] === null ? null : json_encode($definition['options']);

            return $definition;
        }, $definitions);
    }

    // Insert new keys, refresh metadata of existing ones (by name).
    public static function syncSettingDefinitions(): void
    {
        $now = now();

        $rows = array_map(fn (array $row) => array_merge($row, [
            'created_at' => $now,
            'updated_at' => $now,
        ]), static::settingDefinitions());

        DB::table('module_setting_definitions')->upsert(
            $rows,
            ['name'],
            ['type', 'default_value', 'options', 'rules', 'is_required', 'section', 'label', 'help_text', 'updated_at']
        );

        static::convertLegacyProductVideoValues();

        // Cached per-module settings embed each definition's default.
        foreach (DB::table('modules')->pluck('id') as $moduleId) {
            static::clearSettingsCache($moduleId);
        }
    }

    // Module packages built before product_video became a boolean still ship
    // "none" / "link" / "file" / "both". Other values pass through.
    public static function normalizeLegacySettingValue(string $name, mixed $value): mixed
    {
        if ($name === 'product_video' && in_array($value, ['none', 'link', 'file', 'both'], true)) {
            return $value !== 'none';
        }

        return $value;
    }

    // product_video used to be none | link | file | both; it is now a boolean.
    // "none" becomes "0"; any other old value means allowed (the default), so its row goes.
    protected static function convertLegacyProductVideoValues(): void
    {
        $definitionId = DB::table('module_setting_definitions')->where('name', 'product_video')->value('id');

        if (!$definitionId) {
            return;
        }

        $values = DB::table('module_setting_values')->where('module_setting_definition_id', $definitionId);
        (clone $values)->where('value', 'none')->update(['value' => '0']);
        (clone $values)->whereIn('value', ['link', 'file', 'both'])->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Modules page stats
    |--------------------------------------------------------------------------
    */

    // [module_id => ['categories' => n, 'fields' => n, 'listings' => n, 'packages' => n]],
    // every module present even when a count is zero. Computed, cached briefly.
    public static function stats(): array
    {
        return CachingService::cacheRemember(self::STATS_CACHE_KEY, function () {
            $countBy = fn ($query, string $column = 'module_id') => $query
                ->select($column, DB::raw('COUNT(*) as aggregate'))
                ->groupBy($column)
                ->pluck('aggregate', 'module_id');

            $categories = $countBy(DB::table('categories'));
            $fields = $countBy(DB::table('custom_fields'));
            $packages = $countBy(DB::table('packages')->where('is_discontinued', '!=', 1));
            $listings = $countBy(
                DB::table('items')
                    ->join('categories', 'categories.id', '=', 'items.category_id')
                    ->whereNull('items.deleted_at'),
                'categories.module_id'
            );

            return DB::table('modules')->pluck('id')->mapWithKeys(fn ($moduleId) => [$moduleId => [
                'categories' => (int) ($categories[$moduleId] ?? 0),
                'fields' => (int) ($fields[$moduleId] ?? 0),
                'listings' => (int) ($listings[$moduleId] ?? 0),
                'packages' => (int) ($packages[$moduleId] ?? 0),
            ]])->all();
        }, self::STATS_TTL);
    }

    public static function clearStatsCache(): void
    {
        CachingService::removeCache(self::STATS_CACHE_KEY);
    }
}
