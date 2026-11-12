<?php

namespace App\Observers;

use App\Jobs\FetchStoreLogoJob;
use App\Models\Store;
use Throwable;

class StoreObserver
{
    /**
     * Queue a logo fetch for every new store without a logo. A failure never
     * blocks creating the store.
     */
    public function created(Store $store): void
    {
        if (filled($store->logo_path) || ! config('dipalert.fetch_store_logos', true)) {
            return;
        }

        try {
            FetchStoreLogoJob::dispatch($store);
        } catch (Throwable $e) {
            logger()->warning('Could not queue the store logo fetch: '.$e->getMessage(), ['store_id' => $store->getKey()]);
        }
    }
}
