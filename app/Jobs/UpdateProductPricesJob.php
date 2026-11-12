<?php

namespace App\Jobs;

use App\Dto\FetchContext;
use App\Enums\FetchSource;
use App\Models\Product;
use App\Models\Url;
use App\Notifications\ScrapeFailNotification;
use App\Services\PriceFetcherService;
use App\Settings\AppSettings;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Collection;

class UpdateProductPricesJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use Queueable;

    public $timeout = PriceFetcherService::JOB_TIMEOUT;

    public function __construct(public int $productId, public FetchContext $ctx, public bool $logging = false) {}

    /**
     * The fetch context; a job in a run batch carries the batch id.
     */
    public function context(): FetchContext
    {
        return $this->batchId !== null && $this->ctx->source === FetchSource::Run
            ? FetchContext::run($this->batchId)
            : $this->ctx;
    }

    public function handle(): void
    {
        $product = Product::find($this->productId);

        // The product was deleted after the job was queued.
        if (! $product) {
            return;
        }

        if ($this->logging) {
            logger()->info("Starting price fetch for: '{$product->title}'", [
                'product_id' => $product->id,
            ]);
        }

        $failedUrls = $product->updatePrices($this->context());
        $successful = $failedUrls->isEmpty();

        if ($this->logging) {
            $prefix = $successful ? 'Successful' : 'Failed (or partially failed)';
            $method = $successful ? 'info' : 'warning';
            logger()->{$method}("$prefix price fetch for product: '{$product->title}'", [
                'product_id' => $product->id,
            ]);
        }

        if (! $successful) {
            $this->handleFailures($product, $failedUrls);
        }

        // No fixed pause here: the pacing proxy keeps each store's interval.
    }

    /**
     * Schedule a delayed retry for each URL that failed with a retryable outcome
     * (a network error), or notify immediately if delayed retries are disabled.
     * A block, a missing price or a skipped fetch is not retried: another try in
     * 15 minutes would not help.
     *
     * @param  Collection<int, Url>  $failedUrls
     */
    protected function handleFailures(Product $product, Collection $failedUrls): void
    {
        $settings = AppSettings::new();

        if ($settings->scrape_retry_max_attempts < 2) {
            if ($settings->per_product_failure_alerts) {
                $product->user?->notify(new ScrapeFailNotification($product));
            }

            return;
        }

        $delay = now()->addMinutes($settings->scrape_retry_delay_minutes);

        $failedUrls
            ->filter(fn (Url $url) => RetryUrlPriceJob::isRetryable($url))
            ->each(fn (Url $url) => RetryUrlPriceJob::dispatch($url, 2)->delay($delay));
    }
}
