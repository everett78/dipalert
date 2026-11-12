<?php

namespace Tests\Feature\Services;

use App\Dto\FetchContext;
use App\Enums\FetchOutcome;
use App\Jobs\RetryUrlPriceJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class ScrapeUrlRetryTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        Store::factory()->create(['domains' => [['domain' => 'blocked.example']]]);
        Store::factory()->create(['domains' => [['domain' => 'down.example']]]);
        Store::factory()->create(['domains' => [['domain' => 'example.com']]]);
    }

    protected function urlFor(Product $product, string $address): Url
    {
        $store = Store::query()->domainFilter((string) parse_url($address, PHP_URL_HOST))->firstOrFail();

        return Url::factory()->create(['url' => $address, 'product_id' => $product->id, 'store_id' => $store->id]);
    }

    public function test_a_block_is_not_retried_right_away(): void
    {
        Http::fake(['*' => Http::response('blocked', 403)]);

        $result = ScrapeUrl::new('https://blocked.example/item')
            ->withContext(FetchContext::run())
            ->scrape();

        Http::assertSentCount(1);
        $this->assertSame(FetchOutcome::Blocked, $result[ScrapeUrl::OUTCOME_KEY]);
        $this->assertNull($result['title']);
    }

    public function test_a_run_does_not_use_the_page_cache(): void
    {
        $this->mockScrape(12.5, 'Widget');
        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $url = $this->urlFor($product, 'https://example.com/widget');

        $url->scrape(FetchContext::run());
        $url->scrape(FetchContext::run());

        Http::assertSentCount(2);
    }

    public function test_only_a_network_error_gets_a_delayed_retry(): void
    {
        Queue::fake([RetryUrlPriceJob::class]);
        Http::fake([
            'blocked.example/*' => Http::response('blocked', 403),
            'down.example/*' => Http::failedConnection('Connection refused'),
        ]);

        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $this->urlFor($product, 'https://blocked.example/item');
        $down = $this->urlFor($product, 'https://down.example/item');

        (new UpdateProductPricesJob($product->id, FetchContext::run()))->handle();

        Queue::assertPushed(RetryUrlPriceJob::class, 1);
        Queue::assertPushed(RetryUrlPriceJob::class, fn (RetryUrlPriceJob $job): bool => $job->url->is($down));
    }

    public function test_retry_that_ends_in_a_block_stops_without_the_exhausted_notification(): void
    {
        Notification::fake();
        Queue::fake([RetryUrlPriceJob::class]);
        Http::fake(['*' => Http::response('blocked', 403)]);

        $product = Product::factory()->create(['user_id' => $this->user->id]);
        $url = $this->urlFor($product, 'https://blocked.example/item');

        (new RetryUrlPriceJob($url, 3))->handle();

        Queue::assertNotPushed(RetryUrlPriceJob::class);
        Notification::assertNotSentTo($this->user, ScrapeFailNotification::class);
    }

    public function test_job_for_a_deleted_product_ends_without_an_error(): void
    {
        Http::fake();

        (new UpdateProductPricesJob(999999, FetchContext::run()))->handle();

        Http::assertNothingSent();
    }
}
