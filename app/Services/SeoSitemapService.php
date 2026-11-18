<?php

namespace App\Services;

use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\CountryLocationIndexability;
use App\Models\Item;
use App\Models\SeoCombinationStat;
use App\Models\SeoSitemapThreshold;
use App\Models\Setting;
use App\Models\TaxonomySlug;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SeoSitemapService
{
    /**
     * Get base public website URL (e.g. https://jumli.africa)
     */
    public static function getBaseUrl(): string
    {
        $webUrl = Setting::where('name', 'web_url')->value('value');
        if (filled($webUrl)) {
            return rtrim($webUrl, '/');
        }

        return rtrim(config('app.url', 'https://jumli.africa'), '/');
    }

    /**
     * Refresh pre-calculated combination statistics for all or a single country.
     */
    public static function refreshStats(?int $targetCountryId = null): array
    {
        SeoSitemapThreshold::seedDefaults();

        $countriesQuery = Country::where('status', 1);
        if ($targetCountryId) {
            $countriesQuery->where('id', $targetCountryId);
        }
        $countries = $countriesQuery->get();

        // Load all active categories and their descendants
        $allCategories = Category::where('status', 1)->where('is_indexable', 1)->get();
        $catSlugs = TaxonomySlug::where('entity_type', 'category')
            ->get()
            ->groupBy('entity_id')
            ->map(fn($rows) => $rows->pluck('slug', 'locale')->toArray());

        // Preload country location indexability
        $indexability = CountryLocationIndexability::all()
            ->groupBy('country_id')
            ->map(fn($rows) => $rows->pluck('is_indexable', 'level')->toArray());

        // Fast batch aggregation of all active items
        $aggregatedItems = DB::select("
            SELECT
                country,
                city,
                category_id,
                COUNT(*) as cnt,
                MIN(price) as min_p,
                AVG(price) as avg_p
            FROM items
            WHERE status = 'approved'
              AND deleted_at IS NULL
              AND (expiry_date IS NULL OR expiry_date >= CURDATE())
            GROUP BY country, city, category_id
        ");

        // Map aggregated results into an in-memory lookup structure
        $itemsByCountryCityCat = [];
        $itemsByCountryCat = [];
        $itemsByCountryCity = [];
        $itemsByCountry = [];

        foreach ($aggregatedItems as $row) {
            $cNorm = Str::lower(trim(str_replace(['’', "'"], '', $row->country ?? '')));
            $cityNorm = Str::lower(trim($row->city ?? ''));
            $catId = (int)$row->category_id;
            $cnt = (int)$row->cnt;
            $minP = (float)$row->min_p;
            $avgP = (float)$row->avg_p;

            // By country
            if (!isset($itemsByCountry[$cNorm])) {
                $itemsByCountry[$cNorm] = ['count' => 0, 'min_price' => null, 'sum_price' => 0];
            }
            $itemsByCountry[$cNorm]['count'] += $cnt;
            $itemsByCountry[$cNorm]['sum_price'] += ($avgP * $cnt);
            if ($minP > 0 && ($itemsByCountry[$cNorm]['min_price'] === null || $minP < $itemsByCountry[$cNorm]['min_price'])) {
                $itemsByCountry[$cNorm]['min_price'] = $minP;
            }

            // By country + city
            if (!isset($itemsByCountryCity[$cNorm][$cityNorm])) {
                $itemsByCountryCity[$cNorm][$cityNorm] = ['count' => 0, 'min_price' => null, 'sum_price' => 0];
            }
            $itemsByCountryCity[$cNorm][$cityNorm]['count'] += $cnt;
            $itemsByCountryCity[$cNorm][$cityNorm]['sum_price'] += ($avgP * $cnt);
            if ($minP > 0 && ($itemsByCountryCity[$cNorm][$cityNorm]['min_price'] === null || $minP < $itemsByCountryCity[$cNorm][$cityNorm]['min_price'])) {
                $itemsByCountryCity[$cNorm][$cityNorm]['min_price'] = $minP;
            }

            // By country + category
            if (!isset($itemsByCountryCat[$cNorm][$catId])) {
                $itemsByCountryCat[$cNorm][$catId] = ['count' => 0, 'min_price' => null, 'sum_price' => 0];
            }
            $itemsByCountryCat[$cNorm][$catId]['count'] += $cnt;
            $itemsByCountryCat[$cNorm][$catId]['sum_price'] += ($avgP * $cnt);
            if ($minP > 0 && ($itemsByCountryCat[$cNorm][$catId]['min_price'] === null || $minP < $itemsByCountryCat[$cNorm][$catId]['min_price'])) {
                $itemsByCountryCat[$cNorm][$catId]['min_price'] = $minP;
            }

            // By country + city + category
            if (!isset($itemsByCountryCityCat[$cNorm][$cityNorm][$catId])) {
                $itemsByCountryCityCat[$cNorm][$cityNorm][$catId] = ['count' => 0, 'min_price' => null, 'sum_price' => 0];
            }
            $itemsByCountryCityCat[$cNorm][$cityNorm][$catId]['count'] += $cnt;
            $itemsByCountryCityCat[$cNorm][$cityNorm][$catId]['sum_price'] += ($avgP * $cnt);
            if ($minP > 0 && ($itemsByCountryCityCat[$cNorm][$cityNorm][$catId]['min_price'] === null || $minP < $itemsByCountryCityCat[$cNorm][$cityNorm][$catId]['min_price'])) {
                $itemsByCountryCityCat[$cNorm][$cityNorm][$catId]['min_price'] = $minP;
            }
        }

        $now = Carbon::now();
        $recordsToUpsert = [];
        $inSitemapCount = 0;

        foreach ($countries as $country) {
            $cNorm = Str::lower(trim(str_replace(['’', "'"], '', $country->name)));
            $countrySlug = $country->seo_slug;
            $cIndexable = $indexability[$country->id] ?? ['region' => true, 'city' => true];

            // 1. Level 0: Country Page
            $cData = $itemsByCountry[$cNorm] ?? ['count' => 0, 'min_price' => null, 'sum_price' => 0];
            $cCount = $cData['count'];
            $cMinPrice = $cData['min_price'];
            $cAvgPrice = $cCount > 0 ? round($cData['sum_price'] / $cCount, 2) : null;
            $cInSitemap = $cCount >= SeoSitemapThreshold::getThresholdForDepth(0);

            foreach (['fr', 'en'] as $locale) {
                $path = ($locale === 'en' ? "/en/{$countrySlug}/" : "/{$countrySlug}/");
                $recordsToUpsert[] = [
                    'country_id' => $country->id,
                    'location_type' => null,
                    'location_id' => null,
                    'category_id' => null,
                    'locale' => $locale,
                    'url_path' => $path,
                    'listing_count' => $cCount,
                    'min_price' => $cMinPrice,
                    'avg_price' => $cAvgPrice,
                    'depth' => 0,
                    'in_sitemap' => $cInSitemap ? 1 : 0,
                    'last_calculated_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                if ($cInSitemap) $inSitemapCount++;
            }

            // 2. Level 1: Category Pages (in that country)
            $rootCategories = $allCategories->whereNull('parent_category_id');
            foreach ($rootCategories as $cat) {
                $catData = $itemsByCountryCat[$cNorm][$cat->id] ?? ['count' => 0, 'min_price' => null, 'sum_price' => 0];
                $catCount = $catData['count'];
                $catMinPrice = $catData['min_price'];
                $catAvgPrice = $catCount > 0 ? round($catData['sum_price'] / $catCount, 2) : null;
                $catInSitemap = $catCount >= SeoSitemapThreshold::getThresholdForDepth(1);

                $slugMap = $catSlugs[$cat->id] ?? ['fr' => $cat->slug, 'en' => $cat->slug];

                foreach (['fr', 'en'] as $locale) {
                    $cSlug = $slugMap[$locale] ?? $cat->slug;
                    $path = ($locale === 'en' ? "/en/{$countrySlug}/{$cSlug}/" : "/{$countrySlug}/{$cSlug}/");

                    $recordsToUpsert[] = [
                        'country_id' => $country->id,
                        'location_type' => null,
                        'location_id' => null,
                        'category_id' => $cat->id,
                        'locale' => $locale,
                        'url_path' => $path,
                        'listing_count' => $catCount,
                        'min_price' => $catMinPrice,
                        'avg_price' => $catAvgPrice,
                        'depth' => 1,
                        'in_sitemap' => $catInSitemap ? 1 : 0,
                        'last_calculated_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    if ($catInSitemap) $inSitemapCount++;
                }
            }

            // 3. Level 1: Location (City) Pages & Combinations
            if (!empty($cIndexable['city'])) {
                $cities = City::where('country_id', $country->id)->where('status', 1)->get();
                $citySlugs = TaxonomySlug::where('entity_type', 'city')
                    ->whereIn('entity_id', $cities->pluck('id'))
                    ->get()
                    ->groupBy('entity_id')
                    ->map(fn($rows) => $rows->pluck('slug', 'locale')->toArray());

                foreach ($cities as $city) {
                    $cityNorm = Str::lower(trim($city->name));
                    $cityData = $itemsByCountryCity[$cNorm][$cityNorm] ?? ['count' => 0, 'min_price' => null, 'sum_price' => 0];
                    $cityCount = $cityData['count'];
                    $cityMinPrice = $cityData['min_price'];
                    $cityAvgPrice = $cityCount > 0 ? round($cityData['sum_price'] / $cityCount, 2) : null;
                    $cityInSitemap = $cityCount >= SeoSitemapThreshold::getThresholdForDepth(1);

                    $cSlugs = $citySlugs[$city->id] ?? ['fr' => Str::slug($city->name), 'en' => Str::slug($city->name)];

                    foreach (['fr', 'en'] as $locale) {
                        $locSlug = $cSlugs[$locale] ?? Str::slug($city->name);
                        $path = ($locale === 'en' ? "/en/{$countrySlug}/{$locSlug}/" : "/{$countrySlug}/{$locSlug}/");

                        $recordsToUpsert[] = [
                            'country_id' => $country->id,
                            'location_type' => 'city',
                            'location_id' => $city->id,
                            'category_id' => null,
                            'locale' => $locale,
                            'url_path' => $path,
                            'listing_count' => $cityCount,
                            'min_price' => $cityMinPrice,
                            'avg_price' => $cityAvgPrice,
                            'depth' => 1,
                            'in_sitemap' => $cityInSitemap ? 1 : 0,
                            'last_calculated_at' => $now,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                        if ($cityInSitemap) $inSitemapCount++;
                    }

                    // 4. Level 2: Combination Pages (City + Category)
                    foreach ($rootCategories as $cat) {
                        $combData = $itemsByCountryCityCat[$cNorm][$cityNorm][$cat->id] ?? ['count' => 0, 'min_price' => null, 'sum_price' => 0];
                        $combCount = $combData['count'];
                        $combMinPrice = $combData['min_price'];
                        $combAvgPrice = $combCount > 0 ? round($combData['sum_price'] / $combCount, 2) : null;
                        $combInSitemap = $combCount >= SeoSitemapThreshold::getThresholdForDepth(2);

                        $cSlugMap = $catSlugs[$cat->id] ?? ['fr' => $cat->slug, 'en' => $cat->slug];

                        foreach (['fr', 'en'] as $locale) {
                            $locSlug = $cSlugs[$locale] ?? Str::slug($city->name);
                            $catSlug = $cSlugMap[$locale] ?? $cat->slug;
                            $path = ($locale === 'en' ? "/en/{$countrySlug}/{$locSlug}/{$catSlug}/" : "/{$countrySlug}/{$locSlug}/{$catSlug}/");

                            $recordsToUpsert[] = [
                                'country_id' => $country->id,
                                'location_type' => 'city',
                                'location_id' => $city->id,
                                'category_id' => $cat->id,
                                'locale' => $locale,
                                'url_path' => $path,
                                'listing_count' => $combCount,
                                'min_price' => $combMinPrice,
                                'avg_price' => $combAvgPrice,
                                'depth' => 2,
                                'in_sitemap' => $combInSitemap ? 1 : 0,
                                'last_calculated_at' => $now,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];
                            if ($combInSitemap) $inSitemapCount++;
                        }
                    }
                }
            }
        }

        // Perform bulk upserts in batches of 1,000 for maximum performance
        foreach (array_chunk($recordsToUpsert, 1000) as $chunk) {
            SeoCombinationStat::upsert(
                $chunk,
                ['url_path'],
                ['country_id', 'location_type', 'location_id', 'category_id', 'locale', 'listing_count', 'min_price', 'avg_price', 'depth', 'in_sitemap', 'last_calculated_at', 'updated_at']
            );
        }

        return [
            'total_combinations_checked' => count($recordsToUpsert),
            'qualifying_in_sitemap' => $inSitemapCount,
            'refreshed_at' => $now->toIso8601String(),
        ];
    }

    /**
     * Build all XML sitemaps per country + static pages, and the root sitemap index.
     */
    public static function buildSitemaps(): array
    {
        $baseUrl = self::getBaseUrl();
        $sitemapsDir = public_path('sitemaps');

        if (!File::isDirectory($sitemapsDir)) {
            File::makeDirectory($sitemapsDir, 0755, true);
        }

        $nowIso = Carbon::now()->toIso8601String();
        $countries = Country::where('status', 1)->get();
        $generatedFiles = [];

        // 1. Build Per-Country Sitemaps (FR & EN)
        foreach ($countries as $country) {
            $countrySlug = $country->seo_slug;

            foreach (['fr', 'en'] as $locale) {
                $suffix = ($locale === 'en') ? '-en' : '';
                $filename = "sitemap-{$countrySlug}{$suffix}.xml";
                $filePath = "{$sitemapsDir}/{$filename}";

                $urls = [];

                // A. Add qualifying combination/category/location pages passing threshold
                $stats = SeoCombinationStat::where('country_id', $country->id)
                    ->where('locale', $locale)
                    ->where('in_sitemap', true)
                    ->get(['url_path', 'updated_at', 'depth']);

                foreach ($stats as $stat) {
                    $priority = match ($stat->depth) {
                        0 => '1.0',
                        1 => '0.8',
                        2 => '0.7',
                        default => '0.6',
                    };

                    $urls[] = [
                        'loc' => $baseUrl . $stat->url_path,
                        'lastmod' => optional($stat->updated_at)->toIso8601String() ?? $nowIso,
                        'changefreq' => 'daily',
                        'priority' => $priority,
                    ];
                }

                // B. Add active individual ad listings for this country
                $items = Item::where('status', 'approved')
                    ->whereNull('deleted_at')
                    ->where(function ($q) use ($country) {
                        $q->where('country', $country->name)
                          ->orWhere('country', str_replace('’', "'", $country->name))
                          ->orWhere('country_code', $country->iso2)
                          ->orWhereHas('area', fn($a) => $a->where('country_id', $country->id));
                    })
                    ->where(function ($q) {
                        $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', date('Y-m-d'));
                    })
                    ->select('id', 'slug', 'updated_at')
                    ->take(10000) // Keep sitemap sizes well within Google's 50k limit
                    ->get();

                $adPrefix = ($locale === 'en') ? "/en/{$countrySlug}/listing/" : "/{$countrySlug}/annonce/";
                foreach ($items as $item) {
                    $urls[] = [
                        'loc' => $baseUrl . $adPrefix . $item->slug,
                        'lastmod' => optional($item->updated_at)->toIso8601String() ?? $nowIso,
                        'changefreq' => 'weekly',
                        'priority' => '0.5',
                    ];
                }

                // Write Country XML File
                self::writeUrlsetXml($filePath, $urls);

                $generatedFiles[] = [
                    'filename' => $filename,
                    'url' => "{$baseUrl}/sitemaps/{$filename}",
                    'urls_count' => count($urls),
                    'size' => File::size($filePath),
                    'last_modified' => $nowIso,
                ];
            }
        }

        // 2. Build Static Pages Sitemap
        $staticFilename = 'sitemap-static.xml';
        $staticFilePath = "{$sitemapsDir}/{$staticFilename}";
        $staticUrls = [
            ['loc' => $baseUrl . '/', 'lastmod' => $nowIso, 'changefreq' => 'daily', 'priority' => '1.0'],
            ['loc' => $baseUrl . '/about-us', 'lastmod' => $nowIso, 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => $baseUrl . '/contact-us', 'lastmod' => $nowIso, 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => $baseUrl . '/faqs', 'lastmod' => $nowIso, 'changefreq' => 'monthly', 'priority' => '0.5'],
            ['loc' => $baseUrl . '/blogs', 'lastmod' => $nowIso, 'changefreq' => 'weekly', 'priority' => '0.7'],
            ['loc' => $baseUrl . '/privacy-policy', 'lastmod' => $nowIso, 'changefreq' => 'yearly', 'priority' => '0.3'],
            ['loc' => $baseUrl . '/terms-and-condition', 'lastmod' => $nowIso, 'changefreq' => 'yearly', 'priority' => '0.3'],
        ];

        self::writeUrlsetXml($staticFilePath, $staticUrls);
        $generatedFiles[] = [
            'filename' => $staticFilename,
            'url' => "{$baseUrl}/sitemaps/{$staticFilename}",
            'urls_count' => count($staticUrls),
            'size' => File::size($staticFilePath),
            'last_modified' => $nowIso,
        ];

        // 3. Build Root Sitemap Index (/sitemap.xml)
        $rootIndexPath = public_path('sitemap.xml');
        self::writeSitemapIndexXml($rootIndexPath, $generatedFiles);

        return [
            'root_sitemap' => "{$baseUrl}/sitemap.xml",
            'total_files' => count($generatedFiles),
            'files' => $generatedFiles,
        ];
    }

    /**
     * Get real-time status of all generated sitemap files
     */
    public static function getStatus(): array
    {
        $baseUrl = self::getBaseUrl();
        $sitemapsDir = public_path('sitemaps');
        $filesInfo = [];

        if (File::isDirectory($sitemapsDir)) {
            $files = File::files($sitemapsDir);
            foreach ($files as $file) {
                if ($file->getExtension() === 'xml') {
                    $size = $file->getSize();
                    $filesInfo[] = [
                        'filename' => $file->getFilename(),
                        'url' => "{$baseUrl}/sitemaps/" . $file->getFilename(),
                        'size_bytes' => $size,
                        'formatted_size' => round($size / 1024, 2) . ' KB',
                        'last_modified' => Carbon::createFromTimestamp($file->getMTime())->toIso8601String(),
                    ];
                }
            }
        }

        $rootExists = File::exists(public_path('sitemap.xml'));

        return [
            'root_sitemap' => [
                'url' => "{$baseUrl}/sitemap.xml",
                'exists' => $rootExists,
                'last_modified' => $rootExists ? Carbon::createFromTimestamp(File::lastModified(public_path('sitemap.xml')))->toIso8601String() : null,
            ],
            'thresholds' => SeoSitemapThreshold::orderBy('depth')->get(),
            'total_sitemap_files' => count($filesInfo),
            'files' => $filesInfo,
        ];
    }

    /**
     * Write a standard <urlset> sitemap XML file
     */
    protected static function writeUrlsetXml(string $filePath, array $urls): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($urls as $u) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1, 'UTF-8') . "</loc>\n";
            $xml .= "    <lastmod>{$u['lastmod']}</lastmod>\n";
            if (!empty($u['changefreq'])) {
                $xml .= "    <changefreq>{$u['changefreq']}</changefreq>\n";
            }
            if (!empty($u['priority'])) {
                $xml .= "    <priority>{$u['priority']}</priority>\n";
            }
            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>';

        File::put($filePath, $xml);
    }

    /**
     * Write a <sitemapindex> root XML file
     */
    protected static function writeSitemapIndexXml(string $filePath, array $sitemaps): void
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        $xml .= '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        foreach ($sitemaps as $s) {
            $xml .= "  <sitemap>\n";
            $xml .= "    <loc>" . htmlspecialchars($s['url'], ENT_XML1, 'UTF-8') . "</loc>\n";
            $xml .= "    <lastmod>{$s['last_modified']}</lastmod>\n";
            $xml .= "  </sitemap>\n";
        }

        $xml .= '</sitemapindex>';

        File::put($filePath, $xml);
    }
}
