<?php

namespace Tests\Feature\Services\Ai;

use App\Models\Store;
use App\Services\Ai\HealingContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Jez500\WebScraperForLaravel\Facades\WebScraper;
use Jez500\WebScraperForLaravel\WebScraperFake;
use Tests\TestCase;

class HealingContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_validate_returns_matched_value_from_initial_html(): void
    {
        $store = Store::factory()->create(['settings' => []]);
        $context = new HealingContext('https://shop.test/x', $store, '<html><body><b id="p">$9.99</b></body></html>');

        $result = $context->validate('selector', '#p');

        $this->assertTrue($result['matched']);
        $this->assertSame('$9.99', $result['value']);
    }

    public function test_validate_reports_no_match(): void
    {
        $store = Store::factory()->create(['settings' => []]);
        $context = new HealingContext('https://shop.test/x', $store, '<html><body></body></html>');

        $result = $context->validate('selector', '#missing');

        $this->assertFalse($result['matched']);
        $this->assertNotNull($result['error']);
    }

    public function test_validate_reports_invalid_selector_error(): void
    {
        $store = Store::factory()->create(['settings' => []]);
        $context = new HealingContext('https://shop.test/x', $store, '<html><body><span>x</span></body></html>');

        $result = $context->validate('selector', '>>>bad');

        $this->assertFalse($result['matched']);
        $this->assertNotNull($result['error']);
    }

    public function test_fetch_stores_raw_html_and_returns_truncated_copy(): void
    {
        $store = Store::factory()->create(['settings' => []]);
        $html = '<html><body><span id="p">$5.00</span></body></html>';
        WebScraper::shouldReceive('make')->andReturn((new WebScraperFake)->setBody($html));

        $context = new HealingContext('https://shop.test/x', $store, null);
        $returned = $context->fetch(false);

        $this->assertStringContainsString('$5.00', $returned);
        $this->assertTrue($context->validate('selector', '#p')['matched']);
    }

    public function test_tracks_whether_browser_rendering_was_used(): void
    {
        $store = Store::factory()->create(['settings' => []]);
        WebScraper::shouldReceive('make')->andReturn((new WebScraperFake)->setBody('<html></html>'));

        $context = new HealingContext('https://shop.test/x', $store, null);
        $this->assertFalse($context->usedBrowser());

        $context->fetch(false);
        $this->assertFalse($context->usedBrowser());

        // The rendered page comes through FlareSolverr (never the SeleniumBase "api").
        config([
            'dipalert.flaresolverr_url' => 'http://192.168.0.21:30098',
            'dipalert.allowed_internal_hosts' => ['192.168.0.21:30098'],
        ]);
        Http::fake(['192.168.0.21:30098/*' => Http::response([
            'status' => 'ok',
            'solution' => ['url' => 'https://shop.test/x', 'status' => 200, 'response' => '<html>rendered</html>'],
        ])]);

        $context->fetch(true);
        $this->assertTrue($context->usedBrowser());
        $this->assertSame('<html>rendered</html>', $context->getHtml());
    }

    public function test_failed_rendered_fetch_keeps_the_static_html(): void
    {
        $store = Store::factory()->create(['settings' => []]);
        WebScraper::shouldReceive('make')->andReturn((new WebScraperFake)->setBody('<html>static</html>'));

        $context = new HealingContext('https://shop.test/x', $store, null);
        $context->fetch(false);

        // No FlareSolverr configured: the rendered fetch fails and must not wipe the page.
        try {
            $context->fetch(true);
            $this->fail('Expected the rendered fetch to fail');
        } catch (\RuntimeException) {
        }

        $this->assertSame('<html>static</html>', $context->getHtml());
        $this->assertFalse($context->usedBrowser());
    }

    public function test_fetch_surfaces_structured_data_even_when_deep_in_a_large_page(): void
    {
        $store = Store::factory()->create(['settings' => []]);
        // ~68k of leading filler pushes the real product data past the 40k return budget.
        $junk = str_repeat('<div>filler</div>', 4000);
        $html = '<html><head>'.$junk
            .'<script type="application/ld+json">{"@type":"Product","name":"Deep Widget","offers":{"price":48.95,"priceCurrency":"AUD"}}</script>'
            .'<meta property="og:title" content="Deep Widget">'
            .'</head><body></body></html>';
        WebScraper::shouldReceive('make')->andReturn((new WebScraperFake)->setBody($html));

        $context = new HealingContext('https://shop.test/x', $store, null);
        $returned = $context->fetch(false);

        // The JSON-LD price and og:title must be visible to the model despite sitting
        // beyond the truncation window, because fetch leads with the structured signals.
        $this->assertStringContainsString('48.95', $returned);
        $this->assertStringContainsString('og:title', $returned);
        // Validation still runs against the full raw HTML.
        $this->assertTrue($context->validate('regex', '"price"\s*:\s*([0-9.]+)')['matched']);
    }
}
