<?php

namespace App\Console\Commands;

use App\Services\SeoSitemapService;
use Illuminate\Console\Command;

class SeoSitemapBuildCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'seo:sitemap:build {--with-stats : Automatically refresh combination stats before building sitemaps}';

    /**
     * The console command description.
     */
    protected $description = 'Build XML sitemaps per country and the root sitemap index based on listing thresholds';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        if ($this->option('with-stats')) {
            $this->info('Refreshing combination stats first...');
            $statsResult = SeoSitemapService::refreshStats();
            $this->info("Refreshed {$statsResult['total_combinations_checked']} combinations ({$statsResult['qualifying_in_sitemap']} qualified).");
        }

        $this->info('Building XML sitemaps per country and root sitemap index...');
        $startTime = microtime(true);
        $result = SeoSitemapService::buildSitemaps();
        $elapsed = round(microtime(true) - $startTime, 2);

        $this->info("Generated {$result['total_files']} sitemap files in {$elapsed}s.");
        $this->info("Root Sitemap Index: {$result['root_sitemap']}");

        $rows = array_map(function ($f) {
            return [
                $f['filename'],
                $f['urls_count'],
                round($f['size'] / 1024, 2) . ' KB',
            ];
        }, array_slice($result['files'], 0, 10));

        if (count($result['files']) > 10) {
            $rows[] = ['... and ' . (count($result['files']) - 10) . ' more files', '', ''];
        }

        $this->table(['Sitemap File', 'URLs Count', 'File Size'], $rows);

        return Command::SUCCESS;
    }
}
