<?php

namespace App\Jobs;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Does nothing. Sent alone in a run batch when FetchAll has no product to
 * check, so the run still exists and finishes (the daily report reads it).
 */
class NoopJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use Queueable;

    public function handle(): void {}
}
