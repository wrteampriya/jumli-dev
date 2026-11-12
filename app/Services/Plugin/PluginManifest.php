<?php

namespace App\Services\Plugin;

/**
 * Reads a plugin's `module.json` manifest from `app/Modules/{slug}/module.json`.
 *
 * This is the single place that loads plugin manifests. It is used by
 * PluginLicense, PluginManagerController, LicenseInstaller, and
 * PluginDiscoveryServiceProvider, so any change to how manifests are
 * located or parsed should be made here.
 */
class PluginManifest
{
    /**
     * Read and decode the `module.json` manifest for the given plugin.
     *
     * Typical keys: `name`, `slug`, `version`, `description`, `author`,
     * `type`, `main_class`, `settings_fields`, `enabled`.
     *
     * Usage:
     *     $version = PluginManifest::read('cashfree-payment-gateway')['version'] ?? null;
     *
     * @param  string  $slug  The plugin's kebab-case slug, which is also its folder name under `app/Modules` (e.g. "cashfree-payment-gateway").
     * @return array<string, mixed>|null  The decoded manifest, or null when the file is missing or is not a valid JSON object.
     */
    public static function read(string $slug): ?array
    {
        $path = app_path("Modules/{$slug}/module.json");

        if (!file_exists($path)) {
            return null;
        }

        $manifest = json_decode(file_get_contents($path), true);

        return is_array($manifest) ? $manifest : null;
    }
}
