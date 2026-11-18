<?php

namespace App\Console\Commands;

use App\Services\SeoSitemapService;
use Illuminate\Console\Command;

class SeoStatsRefreshCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'seo:stats:refresh {--country= : Optional country ID to refresh only a specific country}';

    /**
     * The console command description.
     */
    protected $description = 'Refresh precomputed combination statistics (listing counts, min price, avg price) for SEO pages';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $this->info('Starting SEO combination stats refresh...');
        $countryId = $this->option('country') ? (int)$this->option('country') : null;

        $startTime = microtime(true);
        $result = SeoSitemapService::refreshStats($countryId);
        $elapsed = round(microtime(true) - $startTime, 2);

        $this->info("Completed stats refresh in {$elapsed}s.");
        $this->table(
            ['Metric', 'Value'],
            [
                ['Combinations Checked', $result['total_combinations_checked']],
                ['Passed Threshold (In Sitemap)', $result['qualifying_in_sitemap']],
                ['Refreshed At', $result['refreshed_at']],
            ]
        );

        return Command::SUCCESS;
    }
}
