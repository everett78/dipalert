<?php

namespace Tests\Feature\Services;

use App\Dto\FetchContext;
use App\Enums\FetchSource;
use App\Enums\Statuses;
use App\Jobs\NoopJob;
use App\Jobs\UpdateAllPricesJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\PriceFetcherService;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PriceFetcherServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Product::query()->delete();
        $this->user = User::factory()->create();
    }

    /**
     * Collect every product id dispatched across all UpdateAllPricesJob jobs.
     *
     * @return array<int, int>
     */
    protected function dispatchedProductIds(): array
    {
        $ids = [];
        Bus::assertDispatched(UpdateAllPricesJob::class, function (UpdateAllPricesJob $job) use (&$ids) {
            $ids = array_merge($ids, $job->productIds);

            return true;
        });

        return $ids;
    }

    /**
     * Product ids of the UpdateProductPricesJob jobs in the run batch, in order.
     *
     * @return array<int, int>
     */
    protected function dispatchedProductJobIds(): array
    {
        $ids = [];
        Bus::assertBatched(function (PendingBatch $batch) use (&$ids): bool {
            foreach ($batch->jobs as $job) {
                if ($job instanceof UpdateProductPricesJob) {
                    $ids[] = $job->productId;
                }
            }

            return true;
        });

        return $ids;
    }

    public function test_global_lane_sends_one_job_per_product_alternating_stores_as_a_run()
    {
        Bus::fake();

        $a = Store::factory()->create();
        $b = Store::factory()->create();
        $products = [];

        foreach ([$a, $a, $b] as $store) {
            $product = Product::factory()->create(['user_id' => $this->user->id]);
            Url::factory()->create(['product_id' => $product->id, 'store_id' => $store->id]);
            $products[] = $product->id;
        }

        PriceFetcherService::new()->updateAllPrices();

        $this->assertSame([$products[0], $products[2], $products[1]], $this->dispatchedProductJobIds());
        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->name === PriceFetcherService::runName(now())
            && $batch->allowsFailures()
            && $batch->jobs->every(fn ($job): bool => $job instanceof UpdateProductPricesJob
                && $job->ctx->source === FetchSource::Run
                && ! $job->ctx->useCache));
        Bus::assertNotDispatched(UpdateAllPricesJob::class);
    }

    public function test_global_lane_without_products_sends_a_run_with_a_noop_job()
    {
        Bus::fake();

        PriceFetcherService::new()->updateAllPrices();

        Bus::assertBatched(fn (PendingBatch $batch): bool => $batch->jobs->count() === 1
            && $batch->jobs->first() instanceof NoopJob);
    }

    public function test_job_in_a_run_batch_uses_the_batch_id()
    {
        $job = (new UpdateProductPricesJob(1, FetchContext::run()))->withBatchId('batch-42');

        $this->assertSame('batch-42', $job->context()->batchId);
        $this->assertSame(FetchSource::Run, $job->context()->source);
    }

    public function test_run_name_is_the_start_minute()
    {
        $this->assertSame('przebieg 2026-11-11 07:00', PriceFetcherService::runName(Carbon::parse('2026-11-11 07:00:42')));
    }

    public function test_due_lane_passes_the_due_context()
    {
        Bus::fake();

        $due = Product::factory()->create(['user_id' => $this->user->id, 'refresh_interval' => 3600]);
        $due->forceFill(['next_check_at' => now()->subMinutes(5)])->saveQuietly();

        PriceFetcherService::new()->updateDuePrices();

        Bus::assertDispatched(UpdateAllPricesJob::class, fn (UpdateAllPricesJob $job): bool => $job->ctx->source === FetchSource::Due);
    }

    public function test_global_lane_skips_paused_and_custom_interval_products()
    {
        Bus::fake();

        $normal = Product::factory()->create(['user_id' => $this->user->id]);
        $paused = Product::factory()->create(['user_id' => $this->user->id, 'paused' => true]);
        $custom = Product::factory()->create(['user_id' => $this->user->id, 'refresh_interval' => 3600]);
        $archived = Product::factory()->create(['user_id' => $this->user->id, 'status' => Statuses::Archived]);

        PriceFetcherService::new()->updateAllPrices();

        $ids = $this->dispatchedProductJobIds();

        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($paused->id, $ids);
        $this->assertNotContains($custom->id, $ids);
        $this->assertNotContains($archived->id, $ids);
    }

    public function test_due_lane_only_dispatches_due_custom_interval_products()
    {
        Bus::fake();

        // Due: custom interval, next check in the past.
        $due = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);
        $due->forceFill(['next_check_at' => now()->subMinutes(5)])->saveQuietly();

        // Not due yet: next check in the future.
        $notDue = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);
        $notDue->forceFill(['next_check_at' => now()->addHour()])->saveQuietly();

        // Paused custom-interval product, due but should be skipped.
        $paused = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
            'paused' => true,
        ]);
        $paused->forceFill(['next_check_at' => now()->subMinutes(5)])->saveQuietly();

        // Global-schedule product (no interval) is not part of this lane.
        $global = Product::factory()->create(['user_id' => $this->user->id]);

        PriceFetcherService::new()->updateDuePrices();

        $ids = $this->dispatchedProductIds();

        $this->assertContains($due->id, $ids);
        $this->assertNotContains($notDue->id, $ids);
        $this->assertNotContains($paused->id, $ids);
        $this->assertNotContains($global->id, $ids);
    }

    public function test_due_lane_reschedules_next_check_into_the_future()
    {
        Bus::fake();

        $due = Product::factory()->create([
            'user_id' => $this->user->id,
            'refresh_interval' => 3600,
        ]);
        $due->forceFill(['next_check_at' => now()->subMinutes(5)])->saveQuietly();

        PriceFetcherService::new()->updateDuePrices();

        $next = $due->fresh()->next_check_at;
        $this->assertTrue($next->greaterThan(now()), 'next_check_at should be pushed into the future');
        // ~1 hour out (interval) plus up to 5 min jitter.
        $this->assertTrue($next->lessThanOrEqualTo(now()->addHour()->addMinutes(6)));
    }
}
