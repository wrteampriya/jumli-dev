<?php

namespace App\Services\License;

use App\Models\Setting;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

// Plugin catalog lives on the validator — GET {validator_url}/api/plugins.
// No local table: the response is cached briefly so the Plugin Manager page
// doesn't hit the validator on every load; refresh() busts that cache.
class MarketplaceCatalogService
{
    protected const CACHE_KEY = 'plugin_catalog';

    protected const CACHE_TTL_SECONDS = 3600;

    protected const LAST_FETCHED_SETTING = 'plugin_catalog_last_fetched';

    // Cached catalog; an unreachable validator yields an empty list rather
    // than breaking the page.
    public function all(): array
    {
        try {
            return Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, fn () => $this->fetch());
        } catch (Throwable $e) {
            logger()->warning('Plugin catalog fetch failed: '.$e->getMessage());

            return [];
        }
    }

    public function find(string $slug): ?array
    {
        return collect($this->all())->firstWhere('slug', $slug);
    }

    // Admin "Sync Plugin" button. Throws on
    // failure so the caller can surface the error.
    public function refresh(): array
    {
        $plugins = $this->fetch();
        Cache::put(self::CACHE_KEY, $plugins, self::CACHE_TTL_SECONDS);

        return ['count' => count($plugins)];
    }

    protected function fetch(): array
    {
        try {
            $response = Http::withHeaders(['X-Product-Key' => config('license.product_key')])
                ->timeout(10)
                ->withOptions([
                    'curl' => [CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4],
                    'version' => '1.1',
                ])
                ->get(rtrim(config('license.validator_url'), '/').'/api/plugins', [
                    'product_slug' => config('license.product_key'),
                ]);
        } catch (ConnectionException $e) {
            throw new Exception('Could not reach the marketplace catalog: '.$e->getMessage());
        }

        if (!$response->successful()) {
            throw new Exception("Marketplace catalog fetch failed (HTTP {$response->status()}).");
        }

        Setting::updateOrCreate(['name' => self::LAST_FETCHED_SETTING], ['value' => now()->toIso8601String()]);

        return collect($response->json('plugins') ?? [])
            ->filter(fn ($plugin) => is_array($plugin) && !empty($plugin['slug']))
            ->map(fn ($plugin) => [
                'slug' => $plugin['slug'],
                'name' => $plugin['name'] ?? $plugin['slug'],
                'scope' => $plugin['scope'] ?? 'universal',
                'description' => $plugin['description'] ?? '',
                'marketplace_url' => $plugin['marketplace_url'] ?? null,
                'codecanyon_url' => $plugin['codecanyon_url'] ?? null,
                'latest_version' => $plugin['latest_version'] ?? null,
            ])
            ->values()
            ->all();
    }
}
