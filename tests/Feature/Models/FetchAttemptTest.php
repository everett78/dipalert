<?php

namespace Tests\Feature\Models;

use App\Dto\FetchContext;
use App\Enums\FetchOutcome;
use App\Enums\FetchSource;
use App\Models\FetchAttempt;
use App\Models\Store;
use App\Models\Url;
use App\Services\AiConfigHealer;
use App\Services\FailedPageStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use Tests\Traits\ScraperTrait;

class FetchAttemptTest extends TestCase
{
    use RefreshDatabase;
    use ScraperTrait;

    protected Url $url;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(FailedPageStore::DISK);

        $store = Store::factory()->create(['domains' => [['domain' => 'example.com']]]);
        $this->url = Url::factory()->create(['url' => 'https://example.com/item', 'store_id' => $store->id]);
    }

    public function test_one_attempt_per_call_even_after_three_tries(): void
    {
        $this->mockScrape('invalid', 'invalid');

        $this->url->updatePrice(null, null, FetchContext::run('batch-1'));

        Http::assertSentCount(3);
        $this->assertSame(1, FetchAttempt::query()->count());

        $attempt = FetchAttempt::query()->firstOrFail();
        $this->assertSame(FetchOutcome::NoPrice, $attempt->outcome);
        $this->assertSame(FetchSource::Run, $attempt->source);
        $this->assertSame('batch-1', $attempt->batch_id);
        $this->assertSame(200, $attempt->http_status);
        $this->assertNotNull($attempt->page_path);
        Storage::disk(FailedPageStore::DISK)->assertExists($attempt->page_path);

        $this->assertSame('no_price', $this->url->fresh()->last_outcome);
        $this->assertNotNull($this->url->fresh()->last_attempt_at);
    }

    public function test_successful_attempt_is_recorded_without_a_page(): void
    {
        $this->mockScrape(25, 'Widget');

        $this->url->updatePrice(null, null, FetchContext::due());

        $attempt = FetchAttempt::query()->firstOrFail();
        $this->assertSame(FetchOutcome::Ok, $attempt->outcome);
        $this->assertSame(FetchSource::Due, $attempt->source);
        $this->assertNull($attempt->page_path);
    }

    public function test_outcome_is_recorded_after_ai_repair(): void
    {
        $this->mock(AiConfigHealer::class, fn ($m) => $m->shouldReceive('heal')
            ->once()
            ->andReturnUsing(fn ($url, array $result) => array_merge($result, ['price' => '19.99'])));

        $this->url->updatePrice(null, ['price' => null, 'title' => 'Widget', 'body' => '<html></html>', 'status' => 200], FetchContext::run());

        $this->assertSame(FetchOutcome::Ok, FetchAttempt::query()->firstOrFail()->outcome);
        $this->assertSame(FetchOutcome::Ok, $this->url->lastFetchOutcome);
    }

    public function test_cache_hit_is_not_an_attempt(): void
    {
        $this->mockScrape(25, 'Widget');

        $this->url->updatePrice(null, null, FetchContext::panel());
        $this->url->updatePrice(null, null, FetchContext::panel());

        Http::assertSentCount(1);
        $this->assertSame(1, FetchAttempt::query()->count());
    }

    public function test_price_given_without_a_fetch_result_is_not_an_attempt(): void
    {
        $this->url->updatePrice(42);

        $this->assertSame(0, FetchAttempt::query()->count());
    }

    public function test_old_attempts_and_their_pages_are_pruned(): void
    {
        $path = FailedPageStore::save('<html>blocked</html>');
        $old = FetchAttempt::query()->create([
            'url_id' => $this->url->id,
            'outcome' => FetchOutcome::Blocked,
            'source' => FetchSource::Run,
            'page_path' => $path,
        ]);
        $old->forceFill(['created_at' => now()->subDays(31)])->save();

        $recent = FetchAttempt::query()->create([
            'url_id' => $this->url->id,
            'outcome' => FetchOutcome::Blocked,
            'source' => FetchSource::Run,
        ]);

        Artisan::call('model:prune', ['--model' => [FetchAttempt::class]]);

        $this->assertModelMissing($old);
        $this->assertModelExists($recent);
        Storage::disk(FailedPageStore::DISK)->assertMissing($path);
    }

    public function test_saved_page_is_cut_to_the_size_limit(): void
    {
        config(['dipalert.failed_page_max_bytes' => 10]);

        $path = FailedPageStore::save(str_repeat('x', 100));

        $this->assertSame(10, strlen((string) Storage::disk(FailedPageStore::DISK)->get($path)));
    }
}
