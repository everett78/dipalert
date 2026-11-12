<?php

namespace Tests\Feature\Services;

use App\Dto\FetchContext;
use App\Enums\ScraperService;
use App\Services\Scrapers\FlareSolverrWebScraper;
use App\Services\Scrapers\ScraperFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class FlareSolverrWebScraperTest extends TestCase
{
    use RefreshDatabase;

    protected const string ENDPOINT = 'http://192.168.0.21:30098/v1';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'dipalert.flaresolverr_url' => 'http://192.168.0.21:30098',
            'dipalert.allowed_internal_hosts' => ['192.168.0.21:30098'],
        ]);
    }

    /**
     * @param  array<string, mixed>  $solution
     */
    protected function fakeSolution(array $solution, string $status = 'ok'): void
    {
        Http::fake([self::ENDPOINT => Http::response([
            'status' => $status,
            'message' => $status === 'ok' ? 'Challenge not detected!' : 'Error: Timeout after 60.0 seconds.',
            'solution' => $solution,
        ])]);
    }

    protected function fetch(FetchContext $ctx, string $url = 'https://allegro.pl/oferta/1'): FlareSolverrWebScraper
    {
        $paced = ScraperFactory::make(ScraperService::FlareSolverr->value, $url, null, $ctx)->get();

        /** @var FlareSolverrWebScraper $inner */
        $inner = $paced->inner();

        return $inner;
    }

    public function test_reads_the_page_status_and_final_address(): void
    {
        $this->fakeSolution([
            'url' => 'https://allegro.pl/oferta/1',
            'status' => 200,
            'response' => '<html><title>Oferta</title></html>',
        ]);

        $scraper = $this->fetch(FetchContext::run());

        $this->assertSame('<html><title>Oferta</title></html>', $scraper->getBody());
        $this->assertSame(200, $scraper->getStatusCode());
        $this->assertSame('https://allegro.pl/oferta/1', $scraper->getFinalUrl());
        $this->assertSame([], $scraper->getErrors());

        Http::assertSent(fn (Request $request): bool => $request->url() === self::ENDPOINT
            && $request['cmd'] === 'request.get'
            && $request['url'] === 'https://allegro.pl/oferta/1');
    }

    public function test_flaresolverr_error_is_a_network_error(): void
    {
        $this->fakeSolution([], 'error');

        $scraper = $this->fetch(FetchContext::run());

        $this->assertArrayHasKey('network', $scraper->getErrors());
        $this->assertSame('', $scraper->getBody());
    }

    public function test_private_final_address_is_refused(): void
    {
        $this->fakeSolution([
            'url' => 'http://192.168.0.1/admin',
            'status' => 200,
            'response' => '<html>router</html>',
        ]);

        $scraper = $this->fetch(FetchContext::run());

        $this->assertArrayHasKey('url_refused', $scraper->getErrors());
        $this->assertSame('', $scraper->getBody());
    }

    public function test_browser_timeout_is_60_s_in_the_background_and_45_s_in_the_panel(): void
    {
        $this->fakeSolution(['url' => 'https://allegro.pl/oferta/1', 'status' => 200, 'response' => 'ok']);

        $this->fetch(FetchContext::run());
        $this->fetch(new FetchContext(\App\Enums\FetchSource::Panel, interactive: true), 'https://allegro.pl/oferta/2');

        Http::assertSent(fn (Request $request): bool => $request['url'] === 'https://allegro.pl/oferta/1' && $request['maxTimeout'] === 60000);
        Http::assertSent(fn (Request $request): bool => $request['url'] === 'https://allegro.pl/oferta/2' && $request['maxTimeout'] === 45000);
    }

    public function test_store_cookies_are_sent_as_a_list(): void
    {
        $scraper = (new FlareSolverrWebScraper)->setUrl('https://allegro.pl/');
        $scraper->setCookies('a=1; b=two=2;  ');

        $this->assertSame([
            ['name' => 'a', 'value' => '1'],
            ['name' => 'b', 'value' => 'two=2'],
        ], $scraper->cookieList());
    }

    public function test_flaresolverr_address_must_be_listed(): void
    {
        config(['dipalert.allowed_internal_hosts' => []]);
        $this->fakeSolution(['url' => 'https://allegro.pl/oferta/1', 'status' => 200, 'response' => 'ok']);

        $scraper = $this->fetch(FetchContext::run());

        $this->assertArrayHasKey('network', $scraper->getErrors());
        Http::assertNothingSent();
    }
}
