<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Country;
use App\Models\Item;
use App\Models\SeoRedirect;
use App\Models\TaxonomySlug;
use App\Services\SeoRedirectService;
use Illuminate\Console\Command;

class SeoRedirectsBuildCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seo:redirects:build {--export= : Optional file path to export CSV (default: storage/app/seo_redirects_audit.csv)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate full SEO redirects snapshot and audit CSV (Annex H WP3)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting SEO Redirects audit generation (WP3)...');
        $startTime = microtime(true);

        $exportPath = $this->option('export') ?: storage_path('app/seo_redirects_audit.csv');
        $handle = fopen($exportPath, 'w');
        if (!$handle) {
            $this->error("Cannot open file for writing at {$exportPath}");
            return Command::FAILURE;
        }

        // Write UTF-8 BOM
        fprintf($handle, chr(0xEF) . chr(0xBB) . chr(0xBF));

        // Header Row
        fputcsv($handle, [
            'Old URL (Indexed)',
            'New Target URL',
            'HTTP Status',
            'Page Type',
            'Ad / Entity Status',
            'Destination Logic & Audit Notes'
        ]);

        $stats = [
            'total_rows' => 0,
            'active_ads' => 0,
            'expired_ads' => 0,
            'deleted_ads' => 0,
            'categories' => 0,
            'custom_rules' => 0,
            'homepage_redirects' => 0,
        ];

        // 1. Custom DB Rules
        $customRules = SeoRedirect::where('is_active', true)->get();
        foreach ($customRules as $rule) {
            fputcsv($handle, [
                $rule->source_path,
                $rule->target_path,
                $rule->status_code,
                'Custom Override',
                'Active',
                $rule->notes ?: 'Manual rule from seo_redirects'
            ]);
            $stats['total_rows']++;
            $stats['custom_rules']++;
            if ($rule->target_path === '/' || str_ends_with($rule->target_path, 'jumli.africa/')) {
                $stats['homepage_redirects']++;
            }
        }

        // 2. Pre-load slugs for maximum performance
        $defaultCountry = Country::where('is_default', 1)->first() ?? Country::first();
        $defaultCountrySlug = $defaultCountry->seo_slug ?? 'cameroun';

        $taxSlugs = TaxonomySlug::select('entity_type', 'entity_id', 'locale', 'slug')->get();
        $slugCache = [
            'default_country_slug' => $defaultCountrySlug,
            'category' => [],
            'city' => [],
        ];
        foreach ($taxSlugs as $s) {
            $slugCache[$s->entity_type][$s->entity_id][$s->locale] = $s->slug;
        }

        // 3. Categories
        $this->info('Processing legacy categories...');
        Category::select('id', 'name', 'slug')->chunk(200, function ($categories) use ($handle, $slugCache, $defaultCountrySlug, &$stats) {
            foreach ($categories as $cat) {
                $frCatSlug = $slugCache['category'][$cat->id]['fr'] ?? $cat->slug;
                $enCatSlug = $slugCache['category'][$cat->id]['en'] ?? $cat->slug;

                fputcsv($handle, [
                    "/ads/{$cat->slug}",
                    "/{$defaultCountrySlug}/{$frCatSlug}/",
                    301,
                    'Category (FR)',
                    'Active',
                    'Legacy category redirected to default country category page'
                ]);

                fputcsv($handle, [
                    "/en/ads/{$cat->slug}",
                    "/en/{$defaultCountrySlug}/{$enCatSlug}/",
                    301,
                    'Category (EN)',
                    'Active',
                    'Legacy category redirected to default country category page'
                ]);

                $stats['total_rows'] += 2;
                $stats['categories'] += 2;
            }
        });

        // 4. Ads
        $this->info('Processing legacy ads...');
        Item::withTrashed()
            ->with(['area.country', 'area.city', 'category'])
            ->select('id', 'name', 'slug', 'status', 'expiry_date', 'deleted_at', 'area_id', 'category_id')
            ->chunk(1000, function ($items) use ($handle, $slugCache, &$stats) {
                foreach ($items as $item) {
                    $resFr = SeoRedirectService::resolveItemDestination($item, false, $slugCache);
                    fputcsv($handle, [
                        "/ad-details/{$item->slug}",
                        $resFr['target'] ?? 'N/A',
                        $resFr['status'],
                        'Ad Page (FR)',
                        $resFr['ad_status'],
                        $resFr['notes']
                    ]);

                    $resEn = SeoRedirectService::resolveItemDestination($item, true, $slugCache);
                    fputcsv($handle, [
                        "/en/ad-details/{$item->slug}",
                        $resEn['target'] ?? 'N/A',
                        $resEn['status'],
                        'Ad Page (EN)',
                        $resEn['ad_status'],
                        $resEn['notes']
                    ]);

                    $stats['total_rows'] += 2;
                    if ($resFr['ad_status'] === 'active') {
                        $stats['active_ads']++;
                    } elseif ($resFr['ad_status'] === 'expired') {
                        $stats['expired_ads']++;
                    } elseif ($resFr['ad_status'] === 'deleted') {
                        $stats['deleted_ads']++;
                    }

                    if (($resFr['target'] ?? '') === '/' || ($resEn['target'] ?? '') === '/') {
                        $stats['homepage_redirects']++;
                    }
                }
            });

        fclose($handle);

        $duration = round(microtime(true) - $startTime, 2);
        $fileSizeMb = round(filesize($exportPath) / (1024 * 1024), 2);

        $this->newLine();
        $this->info("✅ Build & Export completed in {$duration}s!");
        $this->info("📁 CSV Path: {$exportPath} ({$fileSizeMb} MB)");
        $this->newLine();

        $this->table(
            ['Metric', 'Count'],
            [
                ['Total URLs Generated', number_format($stats['total_rows'])],
                ['Active Ads (301 to new URL)', number_format($stats['active_ads'])],
                ['Expired Ads (301 to City+Category)', number_format($stats['expired_ads'])],
                ['Deleted/Fraud Ads (410 Gone)', number_format($stats['deleted_ads'])],
                ['Categories (301 to country category)', number_format($stats['categories'])],
                ['Custom Redirect Rules', number_format($stats['custom_rules'])],
                ['Homepage Redirects (Must be 0)', $stats['homepage_redirects'] === 0 ? '0 (PASSED)' : $stats['homepage_redirects'] . ' (FAILED)'],
            ]
        );

        return Command::SUCCESS;
    }
}
