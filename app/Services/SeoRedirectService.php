<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Country;
use App\Models\Item;
use App\Models\SeoRedirect;
use App\Models\TaxonomySlug;
use Illuminate\Support\Str;

class SeoRedirectService
{
    /**
     * Resolve a path to a redirect destination.
     *
     * @param string $path URL path (e.g. '/ad-details/iphone-11', '/ads/electronics')
     * @param array $queryParams Optional query params
     * @return array|null Returns ['target' => string, 'status' => int, 'type' => string] or null if no redirect
     */
    public static function resolve(string $path, array $queryParams = []): ?array
    {
        if (!config('seo.redirects_enabled', true)) {
            return null;
        }

        $cleanPath = '/' . ltrim(trim($path), '/');
        $cleanPath = rtrim($cleanPath, '/') ?: '/';

        // 1. Check custom DB rules in seo_redirects
        $rule = SeoRedirect::where('source_path', $cleanPath)
            ->where('is_active', true)
            ->first();

        if ($rule) {
            $rule->increment('hits');
            return [
                'target' => $rule->target_path,
                'status' => (int)$rule->status_code,
                'type' => 'custom_rule',
                'notes' => $rule->notes,
            ];
        }

        // 2. Match Legacy Ad URL: /ad-details/{slug}, /fr/ad-details/{slug}, or /en/ad-details/{slug}
        if (preg_match('#^(\/en|\/fr)?\/ad-details\/([a-zA-Z0-9\-_]+)#', $cleanPath, $matches)) {
            $isEn = ($matches[1] ?? '') === '/en';
            $slug = $matches[2];
            $res = self::resolveLegacyAd($slug, $isEn);
            if ($res['status'] !== 404) {
                return $res;
            }
        }

        // 3. Match Legacy Categories: /ads/{category}[/{sub}], /fr/ads/..., or /en/ads/...
        if (preg_match('#^(\/en|\/fr)?\/ads\/(.+)#', $cleanPath, $matches)) {
            $isEn = ($matches[1] ?? '') === '/en';
            $catPath = trim($matches[2], '/');
            $res = self::resolveLegacyCategory($catPath, $isEn);
            if ($res['status'] !== 404) {
                return $res;
            }
        }

        // 4. Match Legacy Query Params on /ads, /fr/ads, or /en/ads (e.g. /ads?category=X&country=Y&city=Z)
        if ($cleanPath === '/ads' || $cleanPath === '/fr/ads' || $cleanPath === '/en/ads') {
            $isEn = str_starts_with($cleanPath, '/en');
            $res = self::resolveLegacyQueryParams($queryParams, $isEn);
            if ($res) {
                return $res;
            }
        }

        return null;
    }

    /**
     * Resolve single-hop destination for legacy ad slugs.
     */
    public static function resolveLegacyAd(string $slug, bool $isEn = false): array
    {
        $item = Item::withTrashed()
            ->with(['area.country', 'area.city', 'category'])
            ->where('slug', $slug)
            ->first();

        if (!$item) {
            return [
                'status' => 404,
                'target' => null,
                'ad_status' => 'not_found',
                'notes' => 'Ad not found in database',
            ];
        }

        return self::resolveItemDestination($item, $isEn);
    }

    /**
     * Resolve single-hop destination directly from an already loaded Item model.
     * Optionally takes an in-memory slugCache to achieve 0-query speed on batch exports.
     */
    public static function resolveItemDestination(Item $item, bool $isEn = false, array $slugCache = []): array
    {
        // 1. Check if deleted / soft-deleted / rejected -> 410 Gone (WP7 & WP3 rule)
        if ($item->trashed() || $item->status === 'rejected') {
            return [
                'status' => 410,
                'target' => null,
                'ad_status' => 'deleted',
                'notes' => 'Ad deleted or rejected -> 410 Gone',
            ];
        }

        // 2. Resolve Country seo_slug
        $countrySlug = $item->area?->country?->seo_slug ?? $slugCache['default_country_slug'] ?? 'cameroun';

        // 3. Check if Expired / Sold out -> 301 to City + Category combination page
        $isExpired = ($item->status === 'sold out') ||
                     ($item->expiry_date !== null && $item->expiry_date <= date('Y-m-d'));

        if ($isExpired) {
            $citySlug = null;
            if ($item->area && $item->area->city) {
                $city = $item->area->city;
                $citySlug = $slugCache['city'][$city->id][$isEn ? 'en' : 'fr']
                    ?? TaxonomySlug::where('entity_type', 'city')
                        ->where('entity_id', $city->id)
                        ->where('locale', $isEn ? 'en' : 'fr')
                        ->value('slug')
                    ?? Str::slug($city->name);
            }

            $catSlug = null;
            if ($item->category) {
                $cat = $item->category;
                $catSlug = $slugCache['category'][$cat->id][$isEn ? 'en' : 'fr']
                    ?? TaxonomySlug::where('entity_type', 'category')
                        ->where('entity_id', $cat->id)
                        ->where('locale', $isEn ? 'en' : 'fr')
                        ->value('slug')
                    ?? $cat->slug;
            }

            if ($citySlug && $catSlug) {
                $target = $isEn ? "/en/{$countrySlug}/{$citySlug}/{$catSlug}/" : "/{$countrySlug}/{$citySlug}/{$catSlug}/";
            } elseif ($catSlug) {
                $target = $isEn ? "/en/{$countrySlug}/{$catSlug}/" : "/{$countrySlug}/{$catSlug}/";
            } elseif ($citySlug) {
                $target = $isEn ? "/en/{$countrySlug}/{$citySlug}/" : "/{$countrySlug}/{$citySlug}/";
            } else {
                $target = $isEn ? "/en/{$countrySlug}/" : "/{$countrySlug}/";
            }

            return [
                'status' => 301,
                'target' => $target,
                'ad_status' => 'expired',
                'notes' => 'Expired ad redirected to City+Category combination page',
            ];
        }

        // 4. Active Ad -> 301 to new canonical ad page
        $slug = $item->slug;
        $target = $isEn
            ? "/en/{$countrySlug}/listing/{$slug}/"
            : "/{$countrySlug}/annonce/{$slug}/";

        return [
            'status' => 301,
            'target' => $target,
            'ad_status' => 'active',
            'notes' => 'Active ad redirected to new canonical ad URL',
        ];
    }

    /**
     * Resolve single-hop destination for legacy category paths (/ads/{category}[/{sub}]).
     */
    public static function resolveLegacyCategory(string $catPath, bool $isEn = false): array
    {
        $segments = array_filter(explode('/', trim($catPath, '/')));
        if (empty($segments)) {
            return [
                'status' => 404,
                'target' => null,
                'ad_status' => 'not_found',
                'notes' => 'No category segment provided',
            ];
        }

        $defaultCountry = Country::where('is_default', 1)->first() ?? Country::first();
        $countrySlug = $defaultCountry->seo_slug ?? 'cameroun';

        $resolvedSegments = [];
        $expectedParentId = null;

        foreach ($segments as $index => $slug) {
            $query = Category::where('slug', $slug);
            if ($index > 0 && $expectedParentId) {
                $query->where('parent_category_id', $expectedParentId);
            }
            $cat = $query->first();

            // If subcategory not found, walk up to deepest found category (Annex B 2.3)
            if (!$cat) {
                if ($index === 0) {
                    return [
                        'status' => 404,
                        'target' => null,
                        'ad_status' => 'not_found',
                        'notes' => 'Root category not found',
                    ];
                }
                break;
            }

            $expectedParentId = $cat->id;

            $localeSlug = $slug;
            if (!$isEn) {
                $frSlug = TaxonomySlug::where('entity_type', 'category')
                    ->where('entity_id', $cat->id)
                    ->where('locale', 'fr')
                    ->value('slug');
                if ($frSlug) {
                    $localeSlug = $frSlug;
                }
            }
            $resolvedSegments[] = $localeSlug;
        }

        $catPathString = implode('/', $resolvedSegments);
        $target = $isEn
            ? "/en/{$countrySlug}/{$catPathString}/"
            : "/{$countrySlug}/{$catPathString}/";

        return [
            'status' => 301,
            'target' => $target,
            'ad_status' => 'active',
            'notes' => 'Legacy category path redirected to default country category page',
        ];
    }

    /**
     * Resolve legacy query params on /ads?country=...&category=...[&city=...]
     */
    public static function resolveLegacyQueryParams(array $queryParams, bool $isEn = false): ?array
    {
        if (empty($queryParams)) {
            return null;
        }

        // 1. Resolve Country
        $countrySlug = null;
        if (!empty($queryParams['country'])) {
            $c = Country::where('name', $queryParams['country'])
                ->orWhere('seo_slug', $queryParams['country'])
                ->orWhere('iso2', $queryParams['country'])
                ->first();
            $countrySlug = $c->seo_slug ?? null;
        }

        if (!$countrySlug) {
            $defaultCountry = Country::where('is_default', 1)->first() ?? Country::first();
            $countrySlug = $defaultCountry->seo_slug ?? 'cameroun';
        }

        // 2. Resolve City
        $citySlug = null;
        if (!empty($queryParams['city'])) {
            $cityVal = $queryParams['city'];
            $city = \App\Models\City::where('id', $cityVal)
                ->orWhere('name', $cityVal)
                ->first();

            if ($city) {
                $citySlug = TaxonomySlug::where('entity_type', 'city')
                    ->where('entity_id', $city->id)
                    ->where('locale', $isEn ? 'en' : 'fr')
                    ->value('slug') ?? Str::slug($city->name);
            }
        }

        // 3. Resolve Category
        $catSlug = null;
        if (!empty($queryParams['category'])) {
            $category = Category::where('slug', $queryParams['category'])
                ->orWhere('name', $queryParams['category'])
                ->first();
            if ($category) {
                $catSlug = $isEn
                    ? $category->slug
                    : (TaxonomySlug::where('entity_type', 'category')->where('entity_id', $category->id)->where('locale', 'fr')->value('slug') ?? $category->slug);
            }
        }

        // 4. Construct Single-Hop Destination Path (Annex H Acceptance Test #4)
        // Never the homepage: always /{country}/...
        if ($citySlug && $catSlug) {
            $target = $isEn ? "/en/{$countrySlug}/{$citySlug}/{$catSlug}/" : "/{$countrySlug}/{$citySlug}/{$catSlug}/";
        } elseif ($citySlug) {
            $target = $isEn ? "/en/{$countrySlug}/{$citySlug}/" : "/{$countrySlug}/{$citySlug}/";
        } elseif ($catSlug) {
            $target = $isEn ? "/en/{$countrySlug}/{$catSlug}/" : "/{$countrySlug}/{$catSlug}/";
        } else {
            // Only filters present (e.g. price, sort) -> default country page, never homepage
            $target = $isEn ? "/en/{$countrySlug}/" : "/{$countrySlug}/";
        }

        return [
            'status' => 301,
            'target' => $target,
            'type' => 'legacy_query',
            'notes' => 'Legacy filter query converted to clean SEO path',
        ];
    }
}
