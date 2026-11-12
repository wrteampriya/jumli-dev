<?php

namespace App\Console\Commands;

use App\Models\AttributionEventLog;
use Illuminate\Console\Command;

class PruneAttributionEventLogs extends Command
{
    protected $signature = 'attribution:prune-logs
        {--keep=1000 : Maximum number of most recent log rows to retain}
        {--days=7 : Delete log rows older than this many days, regardless of count}';
    protected $description = 'Trim the attribution event log diagnostic tail: drop rows older than N days, then cap the remainder to the most recent M rows';

    public function handle()
    {
        $keep = (int) $this->option('keep');
        $days = (int) $this->option('days');

        $expiredDeleted = AttributionEventLog::where('created_at', '<', now()->subDays($days))->delete();

        // Ordered by created_at (actual event time), not id — insertion order isn't a
        // reliable stand-in for recency (e.g. a backfill or seeded row can get a low id
        // despite having a recent timestamp).
        $cutoff = AttributionEventLog::orderByDesc('created_at')
            ->orderByDesc('id')
            ->skip(max($keep - 1, 0))
            ->first(['id', 'created_at']);

        $overflowDeleted = 0;
        if ($cutoff) {
            $overflowDeleted = AttributionEventLog::where('created_at', '<', $cutoff->created_at)
                ->orWhere(function ($query) use ($cutoff) {
                    $query->where('created_at', $cutoff->created_at)
                        ->where('id', '<', $cutoff->id);
                })
                ->delete();
        }

        $this->info("Pruned {$expiredDeleted} row(s) older than {$days} day(s) and {$overflowDeleted} row(s) beyond the latest {$keep}.");
    }
}
