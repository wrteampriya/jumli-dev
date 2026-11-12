<?php

namespace App\Services\Packages;

use App\Models\Module;
use Illuminate\Support\Collection;
use App\Services\Module\ModuleService;

/**
 * Which modules a package type may be created for: active modules whose module setting
 * for that package kind is on (Modules → edit → "Ad Packages" / "Feature Packages").
 * Used by the Ad / Featured package controllers (Module dropdown) and their FormRequests (rule).
 */
class PackageModules
{
    /** Package `type` => the module setting that enables it. */
    public const SETTING_FOR_TYPE = [
        'item_listing' => 'ad_packages_enabled',
        'advertisement' => 'feature_packages_enabled',
    ];

    public static function allowedFor(string $type): Collection
    {
        $setting = self::SETTING_FOR_TYPE[$type];

        return Module::active()->orderBy('sequence')->get()
            ->filter(fn (Module $module) => (bool) ModuleService::setting($module->id, $setting))
            ->values();
    }

    /** The module a new package form starts on: the first allowed one by sequence. */
    public static function defaultFor(string $type): ?Module
    {
        $allowed = self::allowedFor($type);

        return $allowed->first();
    }
}
