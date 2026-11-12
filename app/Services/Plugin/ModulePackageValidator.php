<?php

namespace App\Services\Plugin;

use App\Models\Module;
use App\Models\Setting;
use App\Services\Module\ModuleService;

// Pre-extraction checks for a `type: "module"` plugin zip — everything that
// would make ModuleInstaller fail after the files are already in place.
// Runs from PluginManagerController::extractPluginZip(), so a rejected zip
// never touches app/Modules. See docs/module-plugin-contract.md §2.
class ModulePackageValidator
{
    public const SLUG_PATTERN = '/^[a-z][a-z0-9]*(-[a-z0-9]+)*$/';

    /**
     * @param  array<string, mixed>  $manifest  The zip's decoded module.json.
     * @param  string  $pluginSlug  The plugin's folder/license slug.
     * @return string|null  The first problem found, or null when the package is valid.
     */
    public static function validate(array $manifest, string $pluginSlug): ?string
    {
        $module = $manifest['module'] ?? null;

        if (!is_array($module)) {
            return 'module.json of a module plugin must declare a "module" object.';
        }

        $moduleSlug = $module['slug'] ?? null;

        if (!is_string($moduleSlug) || strlen($moduleSlug) > 64 || !preg_match(self::SLUG_PATTERN, $moduleSlug)) {
            return 'module.slug must be kebab-case (a-z, 0-9, hyphens), at most 64 characters.';
        }

        if (empty($module['name']) || !is_string($module['name'])) {
            return 'module.name is required.';
        }

        if (isset($module['path_prefix']) && (!is_string($module['path_prefix']) || !preg_match(self::SLUG_PATTERN, $module['path_prefix']))) {
            return 'module.path_prefix must be kebab-case.';
        }

        // The slug is the X-Module value: it can't belong to another module.
        $existing = Module::where('slug', $moduleSlug)->first();

        if ($existing && $existing->plugin_slug !== $pluginSlug) {
            return "Module slug [{$moduleSlug}] is already used by another module.";
        }

        $installed = Module::where('plugin_slug', $pluginSlug)->first();

        if ($installed && $installed->slug !== $moduleSlug) {
            return "module.slug cannot change on update (installed as [{$installed->slug}]).";
        }

        if ($error = self::checkCoreRequirement($manifest['requires']['core'] ?? null)) {
            return $error;
        }

        return self::checkSettingValues($manifest['module_setting_values'] ?? []);
    }

    // "requires.core": ">=3.2.0" (also >, <=, <, =; a bare version means >=).
    protected static function checkCoreRequirement(mixed $constraint): ?string
    {
        if ($constraint === null) {
            return null;
        }

        if (!is_string($constraint) || !preg_match('/^\s*(>=|<=|>|<|==|=)?\s*(\d+(?:\.\d+)*)\s*$/', $constraint, $matches)) {
            return 'requires.core must look like ">=3.2.0".';
        }

        $operator = $matches[1] ?: '>=';
        $coreVersion = (string) Setting::getValue('system_version');

        if ($coreVersion === '' || !version_compare($coreVersion, $matches[2], $operator === '=' ? '==' : $operator)) {
            return "This module requires eClassify {$operator}{$matches[2]} (installed: ".($coreVersion ?: 'unknown').').';
        }

        return null;
    }

    protected static function checkSettingValues(mixed $values): ?string
    {
        if (!is_array($values)) {
            return 'module_setting_values must be an object of setting key => value.';
        }

        $definitions = collect(ModuleService::settingDefinitions())->keyBy('name');

        foreach ($values as $name => $value) {
            $definition = $definitions->get($name);

            if (!$definition) {
                return "Unknown module setting [{$name}] in module_setting_values.";
            }

            $value = ModuleService::normalizeLegacySettingValue($name, $value);

            if ($definition['type'] === 'boolean' && !is_bool($value) && !in_array($value, [0, 1], true)) {
                return "Invalid value for module setting [{$name}]; expected true or false.";
            }

            // get() returns options JSON-encoded (it's a json column).
            $options = json_decode($definition['options'] ?? 'null', true);

            if ($options && array_diff((array) $value, $options)) {
                return "Invalid value for module setting [{$name}]; allowed: ".implode(', ', $options).'.';
            }
        }

        return null;
    }
}
