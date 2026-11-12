<?php

namespace Tests\Feature\Services;

use App\Dto\ComparisonOfferDto;
use App\Dto\FetchContext;
use App\Enums\FetchOutcome;
use App\Jobs\RetryUrlPriceJob;
use App\Models\FetchAttempt;
use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\Comparison\ComparisonOfferSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ComparisonOfferSyncTest extends TestCase
{
    use RefreshDatabase;

    protected const string CENEO_URL = 'https://www.ceneo.pl/142485853';

    protected Url $ceneo;

    protected function setUp(): void
    {
        parent::setUp();

        $ceneo = Store::factory()->create([
            'name' => 'Ceneo',
            'domains' => [['domain' => 'ceneo.pl'], ['domain' => 'www.ceneo.pl']],
            'scrape_strategy' => [
                'title' => ['type' => 'schema_org'],
                'price' => ['type' => 'selector', 'value' => 'meta[property=product:price:amount]|content'],
            ],
            'settings' => ['scraper_service' => 'http', 'comparison' => Store::COMPARISON_CENEO],
        ]);

        $product = Product::factory()->create(['user_id' => User::factory()->create()->id]);
        $this->ceneo = Url::factory()->create(['product_id' => $product->id, 'store_id' => $ceneo->id, 'url' => self::CENEO_URL]);
    }

    protected function ceneoPage(string $sample = '20', string $price = '1699'): string
    {
        $html = (string) file_get_contents(base_path('tests/Fixtures/Comparison/ceneo-'.$sample.'.html'));

        return str_replace('</head>', '<meta property="product:price:amount" content="'.$price.'"></head>', $html);
    }

    public function test_offers_become_lines_and_a_second_read_adds_no_duplicates(): void
    {
        Http::fake(['www.ceneo.pl/*' => Http::response($this->ceneoPage())]);

        $this->ceneo->updatePrice(null, null, FetchContext::run());
        $this->ceneo->updatePrice(null, null, FetchContext::run());

        $children = $this->ceneo->children()->with('store')->get();

        $this->assertCount(13, $children);
        $this->assertSame(13, $children->pluck('store_id')->unique()->count());
        $this->assertTrue($children->every(fn (Url $child): bool => $child->last_outcome === 'ok' && $child->last_seen_at !== null));
        $this->assertSame(1699.0, (float) $children->firstWhere('comparison_key', 'mediamarkt.pl')->prices()->latest('id')->first()->price);
        $this->assertSame(2, $children->firstWhere('comparison_key', 'mediamarkt.pl')->prices()->count());

        // Only the Ceneo page was fetched, never an offer link.
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => ! str_contains($request->url(), 'ceneo.pl'));
    }

    public function test_a_store_found_by_domain_is_reused(): void
    {
        $existing = Store::factory()->create(['domains' => [['domain' => 'www.morele.net']]]);

        (new ComparisonOfferSync)->sync($this->ceneo, [new ComparisonOfferDto('morele.net', 'morele.net', 2020.4)]);

        $this->assertSame($existing->id, $this->ceneo->children()->first()->store_id);
    }

    public function test_an_offer_that_left_the_list_gets_no_price(): void
    {
        $sync = new ComparisonOfferSync;
        $sync->sync($this->ceneo, [new ComparisonOfferDto('a.pl', 'A', 10), new ComparisonOfferDto('b.pl', 'B', 11)]);
        $sync->sync($this->ceneo, [new ComparisonOfferDto('a.pl', 'A', 9)]);

        $b = $this->ceneo->children()->where('comparison_key', 'b.pl')->first();

        $this->assertSame('no_price', $b->last_outcome);
        $this->assertSame(1, $b->prices()->count());
        $this->assertSame(0, FetchAttempt::query()->where('url_id', $b->id)->count());
    }

    public function test_empty_offer_list_on_a_page_with_a_price_is_a_no_price_attempt(): void
    {
        Http::fake(['www.ceneo.pl/*' => Http::response('<html><head><title>Ceneo</title><meta property="product:price:amount" content="1699"></head><body>new layout</body></html>')]);

        $this->ceneo->updatePrice(null, null, FetchContext::run());

        $attempt = FetchAttempt::query()->where('url_id', $this->ceneo->id)->firstOrFail();
        $this->assertSame(FetchOutcome::NoPrice, $attempt->outcome);
        $this->assertSame(__('Empty offer list'), $attempt->message);
    }

    public function test_failed_comparison_page_gives_its_lines_the_same_outcome(): void
    {
        (new ComparisonOfferSync)->sync($this->ceneo, [new ComparisonOfferDto('a.pl', 'A', 10)]);
        Http::fake(['www.ceneo.pl/*' => Http::response('blocked', 403)]);

        $this->ceneo->updatePrice(null, null, FetchContext::run());

        $this->assertSame('blocked', $this->ceneo->children()->first()->last_outcome);
    }

    public function test_an_offer_line_never_fetches_a_page(): void
    {
        (new ComparisonOfferSync)->sync($this->ceneo, [new ComparisonOfferDto('a.pl', 'A', 10)]);
        $child = $this->ceneo->children()->first();
        Http::fake();

        $price = $child->updatePrice(null, null, FetchContext::run());
        $this->assertSame(10.0, (float) $price->price);
        $this->assertSame([], $child->scrape(FetchContext::run()));

        $child->lastFetchOutcome = FetchOutcome::NetworkError;
        (new RetryUrlPriceJob($child, 2))->handle();

        Http::assertNothingSent();
        $this->assertSame(0, FetchAttempt::query()->where('url_id', $child->id)->count());
    }
}
