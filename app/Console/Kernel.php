<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\CustomAutoTranslate::class,
        \App\Console\Commands\CustomTranslateMissing::class,
    ];
    /**
     * Define the application's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {
        $schedule->command('notify:expiring-items')->daily();
        $schedule->command('notify:expiring-packages')->daily();
        
        // Process queue jobs every minute
        $schedule->command('queue:process')->everyMinute()->runInBackground()->withoutOverlapping();

        // Make pending transactions failed
        $schedule->command('app:pending-transactions-fail')->everyMinute();

        // Prune stale resized image cache weekly
        $schedule->command('image-cache:clear --days=30')->weekly();

        // Trim the attribution event log diagnostic tail down to the latest 1000 rows
        $schedule->command('attribution:prune-logs')
            ->hourly()
            ->withoutOverlapping(30) // skip this run if the previous one is still going after 30 min (should take seconds)
            ->runInBackground();     // don't block schedule:run / other due tasks while it executes

        // Daily SEO Sitemap Regeneration & Stats Refresh (WP5)
        $schedule->command('seo:sitemap:build --with-stats')
            ->dailyAt('02:00')
            ->withoutOverlapping(60)
            ->runInBackground();

    }

    /**
     * Register the commands for the application.
     *
     * @return void
     */
    protected function commands()
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');

    }



}
