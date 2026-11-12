<?php

namespace App\Console\Commands;

use App\Models\Country;
use App\Services\SeoTaxonomyService;
use Illuminate\Console\Command;

class SeoRegistryRebuildCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'seo:registry:rebuild {--country= : Optional country ID to rebuild}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate taxonomy slugs and rebuild the SEO slug registry';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting SEO Taxonomy and Slug Registry initialization...');

        // 1. Ensure country seo_slugs exist
        $countries = Country::all();
        $this->info("Checking {$countries->count()} countries...");

        foreach ($countries as $index => $country) {
            $changed = false;
            if (empty($country->seo_slug)) {
                $baseSlug = SeoTaxonomyService::slugify($country->name);
                $country->seo_slug = $baseSlug;
                $changed = true;
            }
            if (empty($country->currency_code) && !empty($country->currency)) {
                $country->currency_code = strtoupper(substr($country->currency, 0, 3));
                $changed = true;
            }
            if ($country->is_default === null) {
                // Default first country or Côte d'Ivoire
                $country->is_default = ($country->iso2 === 'CI' || $index === 0);
                $changed = true;
            }

            if ($changed) {
                $country->save();
            }

            SeoTaxonomyService::ensureDefaultLocationIndexability($country->id);
        }

        // Ensure exactly one country is default
        $defaultCount = Country::where('is_default', true)->count();
        if ($defaultCount === 0) {
            $first = Country::first();
            if ($first) {
                $first->update(['is_default' => true]);
            }
        } elseif ($defaultCount > 1) {
            $first = Country::where('is_default', true)->first();
            Country::where('id', '!=', $first->id)->update(['is_default' => false]);
        }

        $targetCountry = $this->option('country') ? (int) $this->option('country') : null;

        $this->info('Rebuilding seo_slug_registry table...');
        $collisions = SeoTaxonomyService::rebuildRegistry($targetCountry);

        if (empty($collisions)) {
            $this->info('SEO Slug Registry rebuilt successfully with 0 collisions!');
        } else {
            $this->warn('Rebuild finished with ' . count($collisions) . ' collisions detected:');
            foreach ($collisions as $col) {
                $this->line("  [{$col['country']}][{$col['locale']}] Slug '{$col['slug']}' collided between '{$col['existing']}' and '{$col['colliding']}'");
            }
        }

        return Command::SUCCESS;
    }
}
