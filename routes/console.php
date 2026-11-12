<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Console Routes
|--------------------------------------------------------------------------
|
| This file is where you may define all of your Closure based console
| commands. Each Closure is bound to a command instance allowing a
| simple approach to interacting with each command's IO methods.
|
*/

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('notify:expiring-items')->daily();
Schedule::command('notify:expiring-packages')->daily();

// Process queue jobs every minute
Schedule::command('queue:process')->everyMinute()->runInBackground()->withoutOverlapping();

// Make pending transactions failed
Schedule::command('app:pending-transactions-fail')->everyMinute();

// Prune stale resized image cache weekly
Schedule::command('image-cache:clear --days=30')->weekly();

// Re-verify plugin licenses past their cached token's expires_hint
Schedule::command('plugin:license:recheck')->daily();

// Trim the attribution event log diagnostic tail down to the latest 1000 rows
Schedule::command('attribution:prune-logs')
    ->hourly()
    ->withoutOverlapping(30)
    ->runInBackground();
