<?php

namespace App\Services;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\CountryLocationIndexability;
use App\Models\Language;
use App\Models\SeoSlugRegistry;
use App\Models\State;
use App\Models\TaxonomySlug;
use App\Models\Translation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SeoTaxonomyService
{
    const CACHE_REGISTRY_KEY = 'seo_slug_registry_map';

    /**
     * Clean transliteration for slugs (strip accents, lowercase, hyphens, no stop-word removal)
     */
    public static function slugify(string $text): string
    {
        $clean = Str::ascii($text);
        return Str::slug($clean, '-');
    }

    /**
     * Validate a country slug against reserved root slugs
     */
    public static function validateCountrySlug(string $slug, ?int $ignoreCountryId = null): void
    {
        $reserved = config('seo.reserved_root_slugs', []);
        if (in_array(strtolower($slug), array_map('strtolower', $reserved), true)) {
            throw new InvalidArgumentException("The slug '{$slug}' is reserved and cannot be used as a country slug.");
        }

        $exists = Country::where('seo_slug', $slug)
            ->when($ignoreCountryId, fn($q) => $q->where('id', '!=', $ignoreCountryId))
            ->exists();

        if ($exists) {
            throw new InvalidArgumentException("The country slug '{$slug}' is already taken.");
        }
    }

    /**
     * Validate a location or category slug against reserved second slugs
     */
    public static function validateSecondSlug(string $slug): void
    {
        $reserved = config('seo.reserved_second_slugs', []);
        if (in_array(strtolower($slug), array_map('strtolower', $reserved), true)) {
            throw new InvalidArgumentException("The slug '{$slug}' is a reserved system keyword and cannot be used for a location or category.");
        }
    }

    /**
     * Suggest an alternative slug when a collision occurs
     */
    public static function suggestSlug(string $slug, string $entityType, int $level): string
    {
        if ($entityType === 'location') {
            if ($level === 1) {
                return $slug . '-region';
            } elseif ($level === 2) {
                return $slug . '-departement';
            } elseif ($level === 3) {
                return $slug . '-ville';
            }
        } elseif ($entityType === 'category') {
            return $slug . '-categorie';
        }

        return $slug . '-1';
    }

    /**
     * Ensure default location indexability for a country exists
     */
    public static function ensureDefaultLocationIndexability(int $countryId): void
    {
        $levels = [
            'region' => true,
            'department' => false,
            'city' => true,
        ];

        foreach ($levels as $level => $isIndexable) {
            CountryLocationIndexability::firstOrCreate(
                [
                    'country_id' => $countryId,
                    'level' => $level,
                ],
                [
                    'is_indexable' => $isIndexable,
                ]
            );
        }
    }

    /**
     * Invalidate cached slug registry
     */
    public static function clearRegistryCache(): void
    {
        Cache::forget(self::CACHE_REGISTRY_KEY);
    }

    /**
     * Rebuild the entire seo_slug_registry from taxonomy tables.
     * Returns an array of detected collisions if any.
     */
    public static function rebuildRegistry(?int $targetCountryId = null): array
    {
        $collisions = [];

        // Preload French language ID
        $frLangId = Language::where('code', 'fr')->value('id');

        $countriesQuery = Country::query();
        if ($targetCountryId) {
            $countriesQuery->where('id', $targetCountryId);
        }
        $countries = $countriesQuery->get();

        // Preload all categories with English slug, French translation, and depth
        $categories = Category::where('status', 1)->where('is_indexable', 1)->get();
        $catData = [];
        foreach ($categories as $cat) {
            $depth = self::calculateCategoryDepth($cat);

            // Fetch French translation name if exists
            $frName = null;
            if ($frLangId) {
                $frName = Translation::where('translatable_type', Category::class)
                    ->where('translatable_id', $cat->id)
                    ->where('language_id', $frLangId)
                    ->where('key', 'name')
                    ->value('value');
            }

            $catData[] = [
                'id' => $cat->id,
                'name' => $cat->name,
                'slug_en' => $cat->slug ?: self::slugify($cat->name),
                'slug_fr' => $frName ? self::slugify($frName) : ($cat->slug ?: self::slugify($cat->name)),
                'level' => $depth,
                'parent_id' => $cat->parent_category_id,
            ];
        }

        foreach ($countries as $country) {
            self::ensureDefaultLocationIndexability($country->id);

            // Fetch indexability rules
            $indexability = CountryLocationIndexability::where('country_id', $country->id)
                ->pluck('is_indexable', 'level')
                ->toArray();

            $regionIndexable = $indexability['region'] ?? true;
            $cityIndexable = $indexability['city'] ?? true;

            // Delete existing registry for this country
            SeoSlugRegistry::where('country_id', $country->id)->delete();

            $registryRows = [];
            $seenSlugs = []; // locale => [slug => entity_description]

            // 1. Process Cities (City, Level 3) - Higher priority for search volume
            if ($cityIndexable) {
                $cities = City::where('country_id', $country->id)->where('status', 1)->get();
                foreach ($cities as $city) {
                    $slugs = self::getEntitySlugs('city', $city->id, $city->name);
                    foreach ($slugs as $locale => $slug) {
                        self::addRegistryItem(
                            $country,
                            $locale,
                            $slug,
                            'location',
                            $city->id,
                            3,
                            $city->state_id,
                            "City · {$city->name} (ID: {$city->id})",
                            $registryRows,
                            $seenSlugs,
                            $collisions
                        );
                    }
                }
            }

            // 2. Process States (Region, Level 1)
            if ($regionIndexable) {
                $states = State::where('country_id', $country->id)->where('status', 1)->get();
                foreach ($states as $state) {
                    $slugs = self::getEntitySlugs('state', $state->id, $state->name);
                    foreach ($slugs as $locale => $slug) {
                        self::addRegistryItem(
                            $country,
                            $locale,
                            $slug,
                            'location',
                            $state->id,
                            1,
                            null,
                            "Region · {$state->name} (ID: {$state->id})",
                            $registryRows,
                            $seenSlugs,
                            $collisions
                        );
                    }
                }
            }

            // 3. Process Categories (Global, repeated per country, Level 1..3)
            foreach ($catData as $c) {
                $catSlugs = [
                    'en' => $c['slug_en'],
                    'fr' => $c['slug_fr'],
                ];

                // Sync with taxonomy_slugs
                TaxonomySlug::updateOrCreate(
                    ['entity_type' => 'category', 'entity_id' => $c['id'], 'locale' => 'en'],
                    ['slug' => $c['slug_en']]
                );
                TaxonomySlug::updateOrCreate(
                    ['entity_type' => 'category', 'entity_id' => $c['id'], 'locale' => 'fr'],
                    ['slug' => $c['slug_fr']]
                );

                foreach ($catSlugs as $locale => $slug) {
                    self::addRegistryItem(
                        $country,
                        $locale,
                        $slug,
                        'category',
                        $c['id'],
                        $c['level'],
                        $c['parent_id'],
                        "Category · {$c['name']} (ID: {$c['id']})",
                        $registryRows,
                        $seenSlugs,
                        $collisions
                    );
                }
            }

            // Bulk insert valid registry items
            foreach (array_chunk($registryRows, 500) as $chunk) {
                SeoSlugRegistry::insert($chunk);
            }
        }

        self::clearRegistryCache();

        return $collisions;
    }

    /**
     * Get or create slugs for an entity across locales (fr, en)
     */
    public static function getEntitySlugs(string $entityType, int $entityId, string $defaultName, ?string $legacyEnglishSlug = null): array
    {
        $existing = TaxonomySlug::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->pluck('slug', 'locale')
            ->toArray();

        $slugs = [];

        // English slug
        if (isset($existing['en'])) {
            $slugs['en'] = $existing['en'];
        } else {
            $enSlug = $legacyEnglishSlug ?: self::slugify($defaultName);
            TaxonomySlug::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'locale' => 'en',
                'slug' => $enSlug,
            ]);
            $slugs['en'] = $enSlug;
        }

        // French slug
        if (isset($existing['fr'])) {
            $slugs['fr'] = $existing['fr'];
        } else {
            $frSlug = self::slugify($defaultName);
            TaxonomySlug::create([
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'locale' => 'fr',
                'slug' => $frSlug,
            ]);
            $slugs['fr'] = $frSlug;
        }

        return $slugs;
    }

    private static function addRegistryItem(
        Country $country,
        string $locale,
        string $slug,
        string $entityType,
        int $entityId,
        int $level,
        ?int $parentId,
        string $description,
        array &$registryRows,
        array &$seenSlugs,
        array &$collisions
    ): void {
        if (isset($seenSlugs[$locale][$slug])) {
            $prev = $seenSlugs[$locale][$slug];
            $collisions[] = [
                'country' => $country->name,
                'locale' => $locale,
                'slug' => $slug,
                'existing' => $prev,
                'colliding' => $description,
                'suggested' => self::suggestSlug($slug, $entityType, $level),
            ];
            return;
        }

        $seenSlugs[$locale][$slug] = $description;
        $registryRows[] = [
            'country_id' => $country->id,
            'locale' => $locale,
            'slug' => $slug,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'level' => $level,
            'parent_id' => $parentId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private static function calculateCategoryDepth(Category $category): int
    {
        $depth = 1;
        $parent = $category->parent_category_id ? Category::find($category->parent_category_id) : null;
        while ($parent) {
            $depth++;
            $parent = $parent->parent_category_id ? Category::find($parent->parent_category_id) : null;
        }
        return min($depth, 3);
    }

    /**
     * Resolve and validate complete URL path segments according to Annex H Figure 2.
     *
     * @param string $countrySlug Segment 1 (e.g. 'cote-divoire')
     * @param array $segments Remaining segments (e.g. ['abidjan', 'immobilier', 'appartements'])
     * @param string $locale 'fr' or 'en'
     * @return array
     */
    public static function resolvePath(string $countrySlug, array $segments, string $locale = 'fr'): array
    {
        // 1. Validate Country (Segment 1)
        $country = Country::where('seo_slug', $countrySlug)->where('status', 1)->first();
        if (!$country) {
            return ['status' => 404, 'error' => 'Unknown country'];
        }

        // If no further segments -> Country Page
        if (empty($segments)) {
            return [
                'status' => 200,
                'page_type' => 'country',
                'country' => [
                    'id' => $country->id,
                    'name' => $country->name,
                    'slug' => $country->seo_slug,
                    'currency' => $country->currency_code,
                ],
                'location' => null,
                'category_chain' => [],
            ];
        }

        $segment2 = $segments[0];

        // 2. Check for Ad discriminator ('annonce' in fr, 'listing' in en)
        $adDiscriminators = ['fr' => 'annonce', 'en' => 'listing'];
        if ($segment2 === ($adDiscriminators[$locale] ?? 'annonce')) {
            $adSlug = $segments[1] ?? null;
            if (!$adSlug || count($segments) > 2) {
                return ['status' => 404, 'error' => 'Invalid ad URL structure'];
            }

            return [
                'status' => 200,
                'page_type' => 'ad',
                'country' => [
                    'id' => $country->id,
                    'name' => $country->name,
                    'slug' => $country->seo_slug,
                ],
                'ad_slug' => $adSlug,
            ];
        }

        // 3. Look up segment 2 in the Seo Slug Registry
        $entry2 = SeoSlugRegistry::where('country_id', $country->id)
            ->where('locale', $locale)
            ->where('slug', $segment2)
            ->first();

        if (!$entry2) {
            return ['status' => 404, 'error' => 'Segment 2 not found in registry'];
        }

        $location = null;
        $categorySegments = [];

        if ($entry2->entity_type === 'location') {
            // Verify this location level is indexable for this country
            $levelMap = [1 => 'region', 2 => 'department', 3 => 'city'];
            $levelName = $levelMap[$entry2->level] ?? 'city';

            $isIndexable = CountryLocationIndexability::where('country_id', $country->id)
                ->where('level', $levelName)
                ->value('is_indexable');

            if (!$isIndexable) {
                return ['status' => 404, 'error' => 'Location level is not indexable'];
            }

            $location = [
                'id' => $entry2->entity_id,
                'level' => $entry2->level,
                'level_name' => $levelName,
                'slug' => $entry2->slug,
            ];

            // Remaining segments must be categories
            $categorySegments = array_slice($segments, 1);

            // If no categories -> Pure Location Page
            if (empty($categorySegments)) {
                return [
                    'status' => 200,
                    'page_type' => 'location',
                    'country' => [
                        'id' => $country->id,
                        'name' => $country->name,
                        'slug' => $country->seo_slug,
                        'currency' => $country->currency_code,
                    ],
                    'location' => $location,
                    'category_chain' => [],
                ];
            }
        } else {
            // Segment 2 is a Category
            $categorySegments = $segments;
        }

        // 4. Validate Category Chain (Must be direct children of each other)
        $categoryChain = [];
        $expectedParentId = null;

        foreach ($categorySegments as $index => $catSlug) {
            $catEntry = SeoSlugRegistry::where('country_id', $country->id)
                ->where('locale', $locale)
                ->where('entity_type', 'category')
                ->where('slug', $catSlug)
                ->first();

            if (!$catEntry) {
                return ['status' => 404, 'error' => "Category slug '{$catSlug}' not found in registry"];
            }

            $category = Category::find($catEntry->entity_id);
            if (!$category || !$category->status || !$category->is_indexable) {
                return ['status' => 404, 'error' => "Category '{$catSlug}' is inactive or not indexable"];
            }

            // Must be direct child of the previous category
            if ($index > 0 && (int)$category->parent_category_id !== (int)$expectedParentId) {
                return ['status' => 404, 'error' => "Category '{$catSlug}' is not a child of the previous category"];
            }

            $expectedParentId = $category->id;
            $categoryChain[] = [
                'id' => $category->id,
                'slug' => $catEntry->slug,
                'name' => $category->name,
                'depth' => $index + 1,
            ];
        }

        if (count($categoryChain) > 3) {
            return ['status' => 404, 'error' => 'Category chain exceeds max depth of 3'];
        }

        $pageType = $location ? 'combination' : 'category';

        return [
            'status' => 200,
            'page_type' => $pageType,
            'country' => [
                'id' => $country->id,
                'name' => $country->name,
                'slug' => $country->seo_slug,
                'currency' => $country->currency_code,
            ],
            'location' => $location,
            'deepest_category_id' => end($categoryChain)['id'],
            'category_chain' => $categoryChain,
        ];
    }

    /**
     * Auto-Sync Category to taxonomy_slugs and seo_slug_registry
     */
    public static function syncCategory(Category $category): void
    {
        if (!$category->status || !$category->is_indexable) {
            self::deleteCategory($category->id);
            return;
        }

        $enSlug = $category->slug ?: self::slugify($category->name);

        $frLangId = Language::where('code', 'fr')->value('id');
        $frName = null;
        if ($frLangId) {
            $frName = Translation::where('translatable_type', Category::class)
                ->where('translatable_id', $category->id)
                ->where('language_id', $frLangId)
                ->where('key', 'name')
                ->value('value');
        }
        $frSlug = $frName ? self::slugify($frName) : $enSlug;

        // 1. taxonomy_slugs
        TaxonomySlug::updateOrCreate(
            ['entity_type' => 'category', 'entity_id' => $category->id, 'locale' => 'en'],
            ['slug' => $enSlug]
        );
        TaxonomySlug::updateOrCreate(
            ['entity_type' => 'category', 'entity_id' => $category->id, 'locale' => 'fr'],
            ['slug' => $frSlug]
        );

        // 2. seo_slug_registry for all active countries
        $depth = self::calculateCategoryDepth($category);
        $countries = Country::where('status', 1)->get();

        foreach ($countries as $country) {
            SeoSlugRegistry::updateOrCreate(
                ['country_id' => $country->id, 'locale' => 'en', 'slug' => $enSlug],
                [
                    'entity_type' => 'category',
                    'entity_id' => $category->id,
                    'level' => $depth,
                    'parent_id' => $category->parent_category_id,
                ]
            );

            SeoSlugRegistry::updateOrCreate(
                ['country_id' => $country->id, 'locale' => 'fr', 'slug' => $frSlug],
                [
                    'entity_type' => 'category',
                    'entity_id' => $category->id,
                    'level' => $depth,
                    'parent_id' => $category->parent_category_id,
                ]
            );
        }
    }

    /**
     * Auto-Remove Category from registry and taxonomy_slugs on delete
     */
    public static function deleteCategory(int $categoryId): void
    {
        TaxonomySlug::where('entity_type', 'category')->where('entity_id', $categoryId)->delete();
        SeoSlugRegistry::where('entity_type', 'category')->where('entity_id', $categoryId)->delete();
    }

    /**
     * Auto-Sync City to taxonomy_slugs and seo_slug_registry
     */
    public static function syncCity(City $city): void
    {
        if (!$city->status) {
            self::deleteCity($city->id);
            return;
        }

        $slug = self::slugify($city->name);
        $countryId = $city->country_id ?? $city->state?->country_id;

        if (!$countryId) {
            return;
        }

        // 1. taxonomy_slugs
        TaxonomySlug::updateOrCreate(
            ['entity_type' => 'city', 'entity_id' => $city->id, 'locale' => 'fr'],
            ['slug' => $slug]
        );
        TaxonomySlug::updateOrCreate(
            ['entity_type' => 'city', 'entity_id' => $city->id, 'locale' => 'en'],
            ['slug' => $slug]
        );

        // 2. seo_slug_registry
        foreach (['fr', 'en'] as $locale) {
            SeoSlugRegistry::updateOrCreate(
                ['country_id' => $countryId, 'locale' => $locale, 'slug' => $slug],
                [
                    'entity_type' => 'location',
                    'entity_id' => $city->id,
                    'level' => 3,
                    'parent_id' => $city->state_id,
                ]
            );
        }
    }

    /**
     * Auto-Remove City from registry and taxonomy_slugs on delete
     */
    public static function deleteCity(int $cityId): void
    {
        TaxonomySlug::where('entity_type', 'city')->where('entity_id', $cityId)->delete();
        SeoSlugRegistry::where('entity_type', 'location')->where('level', 3)->where('entity_id', $cityId)->delete();
    }
}
