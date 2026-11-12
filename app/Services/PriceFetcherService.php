<?php

namespace App\Services;

use App\Dto\FetchContext;
use App\Jobs\UpdateAllPricesJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

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

    /**
     * Global lane (a scheduled run): fetch every published product that follows
     * the global schedule (no custom interval) and isn't paused. One job per
     * product, sent in an order that alternates between stores.
     */
    public function updateAllPrices(): void
    {
        $products = Product::query()
            ->select('id')
            ->published()
            ->where('paused', false)
            ->whereNull('refresh_interval')
            ->with('urls:id,product_id,store_id')
            ->get();

        // The run (job batch) is added in stage 3.
        $ctx = FetchContext::run(null);

        foreach (FetchScheduler::order($products) as $productId) {
            UpdateProductPricesJob::dispatch($productId, $ctx, $this->logging);
        }
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
