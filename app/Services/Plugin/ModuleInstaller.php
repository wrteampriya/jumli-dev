<?php

namespace App\Services\Plugin;

use App\Models\Module;
use App\Models\ModuleSettingDefinition;
use App\Models\ModuleSettingValue;
use Illuminate\Http\File as HttpFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use App\Services\Module\ModuleService;

// Turns an extracted `type: "module"` plugin into a live module: runs the
// plugin's own migrations, then creates (install) or refreshes (update) its
// `modules` row and seeds its module_setting_values. Called by
// PluginManagerController after the zip passed ModulePackageValidator.
class ModuleInstaller
{
    public function install(string $pluginSlug): Module
    {
        $manifest = PluginManifest::read($pluginSlug);

        if (!$manifest || !is_array($manifest['module'] ?? null)) {
            throw new RuntimeException("Plugin [{$pluginSlug}] has no module definition in module.json.");
        }

        $this->runMigrations($pluginSlug);

        $module = DB::transaction(function () use ($pluginSlug, $manifest) {
            $module = $this->saveModuleRow($pluginSlug, $manifest);
            $this->seedSettingValues($module, $manifest['module_setting_values'] ?? []);

            return $module;
        });

        ModuleService::clearSettingsCache($module->id);
        ModuleService::clearStatsCache();

        return $module;
    }

    // Explicit --path: the plugin's ServiceProvider wasn't discovered in this
    // request (its folder didn't exist at boot), so loadMigrationsFrom()
    // hasn't run. Later `migrate` calls (system updater) pick them up via
    // the provider.
    protected function runMigrations(string $pluginSlug): void
    {
        $relativePath = "app/Modules/{$pluginSlug}/database/migrations";

        if (!is_dir(base_path($relativePath))) {
            return;
        }

        $exitCode = Artisan::call('migrate', ['--path' => $relativePath, '--force' => true]);

        if ($exitCode !== 0) {
            throw new RuntimeException('Module migrations failed: '.trim(Artisan::output()));
        }
    }

    // Install creates the row;
    // update only bumps the version — name, icon, sequence and translations
    // belong to the admin after install.
    protected function saveModuleRow(string $pluginSlug, array $manifest): Module
    {
        $definition = $manifest['module'];

        $module = Module::where('plugin_slug', $pluginSlug)->first();

        if ($module) {
            $module->update(['version' => $manifest['version'] ?? $module->version]);

            if (!$module->getRawOriginal('icon') && ($icon = $this->storeIcon($pluginSlug, $definition['icon'] ?? null))) {
                $module->update(['icon' => $icon]);
            }

            return $module;
        }

        return Module::create([
            'slug' => $definition['slug'],
            'name' => $definition['name'],
            'description' => $definition['description'] ?? null,
            'icon' => $this->storeIcon($pluginSlug, $definition['icon'] ?? null),
            'path_prefix' => $definition['path_prefix'] ?? $definition['slug'],
            'sequence' => $definition['sequence'] ?? ((int) Module::max('sequence') + 1),
            'status' => true,
            'version' => $manifest['version'] ?? null,
            'plugin_slug' => $pluginSlug,
        ]);
    }

    // Insert-only: a key the module already has a value for was set by the
    // admin (or an earlier install) and is never overwritten. Same storage
    // rule as ModuleController::saveModuleSettings — a value equal to the
    // default gets no row.
    protected function seedSettingValues(Module $module, array $values): void
    {
        if (!$values) {
            return;
        }

        $definitions = ModuleSettingDefinition::whereIn('name', array_keys($values))->get()->keyBy('name');
        $existing = ModuleSettingValue::where('module_id', $module->id)->pluck('module_setting_definition_id')->all();

        foreach ($values as $name => $value) {
            $definition = $definitions->get($name);

            if (!$definition || in_array($definition->id, $existing)) {
                continue;
            }

            $value = ModuleService::normalizeLegacySettingValue($name, $value);

            $stored = match ($definition->type) {
                'array' => implode(',', (array) $value),
                'boolean' => $value ? '1' : '0',
                'integer' => (string) (int) $value,
                default => (string) $value,
            };

            if ($stored === $definition->default_value) {
                continue;
            }

            ModuleSettingValue::create([
                'module_id' => $module->id,
                'module_setting_definition_id' => $definition->id,
                'value' => $stored,
            ]);
        }
    }

    // module.icon is a path inside the plugin folder; copied to the same
    // `modules/` upload folder the admin edit form uses.
    protected function storeIcon(string $pluginSlug, ?string $icon): ?string
    {
        if (!$icon) {
            return null;
        }

        $source = realpath(app_path("Modules/{$pluginSlug}/{$icon}"));

        if (!$source || !str_starts_with($source, realpath(app_path("Modules/{$pluginSlug}")).DIRECTORY_SEPARATOR) || !is_file($source)) {
            return null;
        }

        return Storage::disk(config('filesystems.default'))
            ->putFileAs('modules', new HttpFile($source), uniqid('', true).time().'.'.pathinfo($source, PATHINFO_EXTENSION));
    }
}
