<?php

namespace Tests\Feature\Services;

use App\Dto\FetchContext;
use App\Enums\FetchOutcome;
use App\Models\Store;
use App\Models\Url;
use App\Services\AiConfigHealer;
use App\Services\ScrapeUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Mockery;
use Tests\TestCase;

class RedirectGuardTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = Store::factory()->create(['domains' => [['domain' => 'shop.example']]]);
    }

    public function test_redirect_to_a_private_address_is_refused_without_a_second_request(): void
    {
        Http::fake([
            'shop.example/*' => Http::response('', 302, ['Location' => 'http://192.168.0.1/admin']),
            '192.168.0.1/*' => Http::response('router admin', 200),
        ]);

        $result = ScrapeUrl::new('https://shop.example/item')
            ->withContext(FetchContext::run())
            ->scrape();

        $this->assertSame(FetchOutcome::UrlRefused, $result[ScrapeUrl::OUTCOME_KEY]);
        $this->assertArrayHasKey('url_refused', $result['errors']);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '192.168.0.1'));
    }

    public function test_connection_error_is_a_network_error_without_ai_repair(): void
    {
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $healer = Mockery::mock(AiConfigHealer::class);
        $healer->shouldNotReceive('heal');
        $healer->shouldNotReceive('healStoreForUrl');
        $this->app->instance(AiConfigHealer::class, $healer);

        $url = Url::factory()->create(['url' => 'https://shop.example/item', 'store_id' => $this->store->id]);

        $this->assertNull($url->updatePrice(null, null, FetchContext::run()));
        $this->assertSame(FetchOutcome::NetworkError, $url->lastFetchOutcome);
    }
}
