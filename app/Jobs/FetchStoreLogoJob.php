<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\StoreLogoFetcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Fetches a store logo once, without retries (a missing logo shows initials).
 */
class FetchStoreLogoJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    public $tries = 1;

    public $timeout = 120;

    public bool $deleteWhenMissingModels = true;

    public function __construct(public Store $store) {}

    public function handle(): void
    {
        StoreLogoFetcher::new()->fetch($this->store);
    }
}
