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
}
