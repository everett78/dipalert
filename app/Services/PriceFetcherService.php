<?php

namespace App\Services;

use App\Dto\FetchContext;
use App\Jobs\NoopJob;
use App\Jobs\UpdateAllPricesJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use Carbon\CarbonInterface;
use Illuminate\Bus\Batch;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\Bus;

class PriceFetcherService
{
    public const JOB_TIMEOUT = 1200; // 20 minutes

    protected array $config;

    protected bool $logging = false;

    public function __construct()
    {
        $this->config = config('price_buddy');
    }

    public static function new(): self
    {
        return resolve(static::class);
    }

    public function setLogging(bool $logging): self
    {
        $this->logging = $logging;

        return $this;
    }

    public const string RUN_PREFIX = 'przebieg ';

    /**
     * Name of the run batch started at the given time, e.g. "przebieg 2026-11-11 07:00".
     */
    public static function runName(CarbonInterface $start): string
    {
        return self::RUN_PREFIX.$start->copy()->startOfMinute()->format('Y-m-d H:i');
    }

    /**
     * Global lane (a scheduled run): fetch every published product that follows
     * the global schedule (no custom interval) and isn't paused. One job per
     * product, sent in an order that alternates between stores, as one job
     * batch named after the start time. With no product the batch holds one
     * NoopJob, so the run still exists and finishes.
     */
    public function updateAllPrices(): ?Batch
    {
        $products = Product::query()
            ->select('id')
            ->published()
            ->where('paused', false)
            ->whereNull('refresh_interval')
            ->with('urls:id,product_id,store_id')
            ->get();

        // The batch id is known only once the batch exists; each job builds its
        // context from its own batch id.
        $jobs = array_map(
            fn (int $productId) => new UpdateProductPricesJob($productId, FetchContext::run(), $this->logging),
            FetchScheduler::order($products),
        );

        if ($jobs === []) {
            $jobs = [new NoopJob];
        }

        return Bus::batch($jobs)
            ->name(self::runName(now()))
            ->allowFailures()
            ->dispatch();
    }

    /**
     * Per-product lane: fetch published, non-paused products with a custom
     * interval that are now due. Each chunk's next check is only pushed into the
     * future once its job has been dispatched, so a failed dispatch can't
     * prematurely advance next_check_at and silently skip those products.
     */
    public function updateDuePrices(): void
    {
        $due = Product::query()
            ->published()
            ->where('paused', false)
            ->whereNotNull('refresh_interval')
            ->where(fn ($query) => $query
                ->whereNull('next_check_at')
                ->orWhere('next_check_at', '<=', now())
            )
            ->with('urls:id,product_id,store_id')
            ->get(['id', 'refresh_interval', 'next_check_at']);

        $ordered = $this->sortByIds($due, FetchScheduler::order($due));

        $ordered->chunk((int) data_get($this->config, 'chunk_size'))
            ->each(function (EloquentCollection $chunk): void {
                UpdateAllPricesJob::dispatch($chunk->pluck('id')->values()->toArray(), FetchContext::due());

                $chunk->each(fn (Product $product) => $product->scheduleNextCheck());
            });
    }

    /**
     * Products in the order of the given ids.
     */
    public function getProducts(array $productIds): EloquentCollection
    {
        return $this->sortByIds(Product::whereIn('id', $productIds)->get(), $productIds);
    }

    public function updatePrices(array $productIds, ?FetchContext $ctx = null): EloquentCollection
    {
        $ctx ??= FetchContext::panelQueued();

        return $this
            ->getProducts($productIds)
            ->each(function ($product) use ($ctx) {
                /** @var Product $product */
                UpdateProductPricesJob::dispatch((int) $product->getKey(), $ctx, $this->logging);
            });
    }

    /**
     * @param  EloquentCollection<int, Product>  $products
     * @param  array<int, int|string>  $ids
     * @return EloquentCollection<int, Product>
     */
    protected function sortByIds(EloquentCollection $products, array $ids): EloquentCollection
    {
        $positions = array_flip(array_map('intval', array_values($ids)));

        return $products
            ->sortBy(fn (Product $product) => $positions[(int) $product->getKey()] ?? PHP_INT_MAX)
            ->values();
    }
}
