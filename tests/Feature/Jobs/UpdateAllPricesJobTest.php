<?php

namespace Tests\Feature\Jobs;

use App\Dto\FetchContext;
use App\Enums\FetchSource;
use App\Jobs\UpdateAllPricesJob;
use App\Models\Product;
use App\Models\User;
use App\Services\PriceFetcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UpdateAllPricesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Product::all()->each(fn ($product) => $product->delete());
    }

    public function test_job_calls_price_fetcher_service_with_product_ids()
    {
        $user = User::factory()->create();
        $products = Product::factory()->count(3)->create(['user_id' => $user->id]);
        $productIds = $products->pluck('id')->toArray();

        $this->mock(PriceFetcherService::class, function ($mock) use ($productIds, $products) {
            $mock->shouldReceive('setLogging')
                ->once()
                ->with(true)
                ->andReturnSelf();

            $mock->shouldReceive('updatePrices')
                ->once()
                ->withArgs(fn (array $ids, FetchContext $ctx): bool => $ids === $productIds
                    && $ctx->source === FetchSource::Due)
                ->andReturn($products);
        });

        $job = new UpdateAllPricesJob($productIds, FetchContext::due());
        $job->handle();
    }

    public function test_job_without_a_context_is_a_queued_panel_fetch()
    {
        $job = new UpdateAllPricesJob([1]);

        $this->assertSame(FetchSource::Panel, $job->ctx->source);
        $this->assertFalse($job->ctx->interactive);
        $this->assertFalse($job->ctx->useCache);
    }

    public function test_job_can_be_instantiated_without_product_ids()
    {
        $job = new UpdateAllPricesJob;

        $this->assertInstanceOf(UpdateAllPricesJob::class, $job);
    }

    public function test_job_has_correct_timeout()
    {
        $job = new UpdateAllPricesJob([]);

        $this->assertSame(PriceFetcherService::JOB_TIMEOUT, $job->timeout);
    }
}
