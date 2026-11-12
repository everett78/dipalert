<?php

namespace Tests\Feature\Services;

use App\Dto\FetchContext;
use App\Enums\FetchOutcome;
use App\Enums\FetchSource;
use App\Enums\ScraperService;
use App\Jobs\AddUrlToProductJob;
use App\Jobs\CreateProductFromUrlJob;
use App\Jobs\UpdateAllPricesJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\Ai\HealingContext;
use App\Services\AiConfigHealer;
use App\Services\AiScrapeEnhancer;
use App\Services\Helpers\SettingsHelper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Once;
use Mockery;
use Tests\TestCase;

class AiGateTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected Url $url;

    protected function setUp(): void
    {
        parent::setUp();

        // No real requests to the AI provider. Tests fake their pages themselves
        // (the first matching fake wins, so no catch-all here).
        Http::fake(['ai.example*' => Http::response(['error' => 'not available'], 500)]);

        SettingsHelper::setSetting('integrated_services', ['ai' => [
            'enabled' => true,
            'default_provider_id' => 'p1',
            'providers' => [[
                'id' => 'p1', 'name' => 'Local', 'type' => 'ollama',
                'base_url' => 'http://ai.example:11434', 'model' => 'm',
            ]],
        ]]);
        SettingsHelper::$settings = null;
        Cache::flush();
        Once::flush();

        $this->store = Store::factory()->create([
            'domains' => [['domain' => 'shop.example']],
            'settings' => ['scraper_service' => ScraperService::Http->value, 'ai_extraction_enabled' => true],
        ]);
        $product = Product::factory()->create();
        $this->url = Url::factory()->create([
            'url' => 'https://shop.example/item',
            'product_id' => $product->id,
            'store_id' => $this->store->id,
        ]);
    }

    protected function expectNoAi(): void
    {
        $healer = Mockery::mock(AiConfigHealer::class);
        $healer->shouldNotReceive('heal');
        $healer->shouldNotReceive('healStoreForUrl');
        $this->app->instance(AiConfigHealer::class, $healer);

        $enhancer = Mockery::mock(AiScrapeEnhancer::class);
        $enhancer->shouldNotReceive('enhance');
        $this->app->instance(AiScrapeEnhancer::class, $enhancer);
    }

    /**
     * @return array<string, mixed>
     */
    protected function scrapeResult(FetchOutcome $outcome, ?int $status = 200, array $errors = []): array
    {
        return [
            'store' => $this->store,
            'title' => null,
            'price' => null,
            'body' => '<html>page</html>',
            'status' => $status,
            'errors' => $errors,
            'outcome' => $outcome,
        ];
    }

    public function test_no_ai_after_a_block(): void
    {
        $this->expectNoAi();

        $this->url->updatePrice(null, $this->scrapeResult(FetchOutcome::Blocked, 403), FetchContext::run());

        $this->assertSame(FetchOutcome::Blocked, $this->url->lastFetchOutcome);
    }

    public function test_no_ai_after_a_skipped_fetch(): void
    {
        $this->expectNoAi();

        $this->url->updatePrice(null, $this->scrapeResult(FetchOutcome::Skipped, null, ['skipped' => ['message' => 'paused']]), FetchContext::run());

        $this->assertSame(FetchOutcome::Skipped, $this->url->lastFetchOutcome);
    }

    public function test_no_store_repair_when_adding_a_link_to_a_blocking_store(): void
    {
        $this->expectNoAi();
        Http::fake(['*' => Http::response('blocked', 403)]);

        $result = Url::createFromUrl('https://shop.example/other', $this->url->product_id, $this->url->product->user_id, ctx: FetchContext::panelQueued());

        $this->assertFalse($result);
    }

    public function test_no_price_in_the_panel_queues_the_repair_instead_of_running_ai(): void
    {
        $this->expectNoAi();
        Queue::fake([UpdateAllPricesJob::class]);

        $this->url->updatePrice(null, $this->scrapeResult(FetchOutcome::NoPrice), FetchContext::panel());

        Queue::assertPushed(UpdateAllPricesJob::class, fn (UpdateAllPricesJob $job): bool => $job->productIds === [$this->url->product_id]
            && $job->ctx->source === FetchSource::Panel
            && ! $job->ctx->interactive);
        $this->assertTrue(Url::$lastRepairQueued);
    }

    public function test_no_price_in_the_background_runs_the_repair(): void
    {
        $healer = Mockery::mock(AiConfigHealer::class);
        $healer->shouldReceive('heal')->once()->andReturnUsing(fn ($url, array $result) => $result);
        $this->app->instance(AiConfigHealer::class, $healer);

        $this->url->updatePrice(null, $this->scrapeResult(FetchOutcome::NoPrice), FetchContext::run());

        $this->assertSame(FetchOutcome::NoPrice, $this->url->lastFetchOutcome);
    }

    public function test_repair_fetches_with_the_store_method_or_flaresolverr_never_api(): void
    {
        $http = new HealingContext('https://shop.example/item', new Store(['settings' => ['scraper_service' => 'http']]));
        $this->assertSame('http', $http->serviceFor(false));
        $this->assertSame('flaresolverr', $http->serviceFor(true));

        $flare = new HealingContext('https://shop.example/item', new Store(['settings' => ['scraper_service' => 'flaresolverr']]));
        $this->assertSame('flaresolverr', $flare->serviceFor(false));
        $this->assertSame('flaresolverr', $flare->serviceFor(true));

        $api = new HealingContext('https://shop.example/item', new Store(['settings' => ['scraper_service' => 'api']]));
        $this->assertSame('http', $api->serviceFor(false));
        $this->assertSame('flaresolverr', $api->serviceFor(true));
    }

    public function test_repair_never_changes_the_fetch_method(): void
    {
        AiConfigHealer::new()->applyPreviewToStore($this->store, ['fields' => [], 'extracted' => [], 'usedBrowser' => true]);

        $this->assertSame(ScraperService::Http->value, $this->store->scraper_service);
        $this->assertSame(ScraperService::FlareSolverr->value, $this->store->suggested_scraper_service);
    }

    public function test_new_product_without_a_price_in_the_panel_is_created_in_the_background(): void
    {
        $this->expectNoAi();
        Queue::fake([CreateProductFromUrlJob::class, AddUrlToProductJob::class]);
        Http::fake(['*' => Http::response('<html><head><meta property="og:title" content="Widget"></head></html>')]);
        $user = User::factory()->create();

        $result = Url::createFromUrl('https://shop.example/new', userId: $user->id, ctx: FetchContext::panel());

        $this->assertFalse($result);
        $this->assertTrue(Url::$lastRepairQueued);
        Queue::assertPushed(CreateProductFromUrlJob::class, fn (CreateProductFromUrlJob $job): bool => $job->url === 'https://shop.example/new'
            && $job->userId === $user->id);
    }

    public function test_link_without_a_store_in_the_panel_is_added_in_the_background(): void
    {
        $this->expectNoAi();
        Queue::fake([CreateProductFromUrlJob::class, AddUrlToProductJob::class]);
        Http::fake(['*' => Http::response('<html><body>no product data</body></html>')]);

        $result = Url::createFromUrl('https://unknown.example/item', $this->url->product_id, $this->url->product->user_id, ctx: FetchContext::panel());

        $this->assertFalse($result);
        Queue::assertPushed(AddUrlToProductJob::class, fn (AddUrlToProductJob $job): bool => $job->url === 'https://unknown.example/item'
            && $job->productId === $this->url->product_id);
    }
}
