<?php

namespace Tests\Unit\Services;

use App\Jobs\FetchStoreLogoJob;
use App\Models\Store;
use App\Services\StoreLogoFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StoreLogoFetcherTest extends TestCase
{
    use RefreshDatabase;

    protected const string PNG = "\x89PNG\r\n\x1a\n0000IHDRfake-png-body";

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(StoreLogoFetcher::DISK);
        $this->store = Store::factory()->create(['domains' => [['domain' => 'shop.example']]]);
    }

    protected function homePage(string $head): string
    {
        return '<html><head>'.$head.'</head><body>Shop</body></html>';
    }

    public function test_picks_the_largest_icon(): void
    {
        Http::fake([
            'shop.example/' => Http::response($this->homePage(
                '<link rel="icon" sizes="16x16" href="/small.png">'
                .'<link rel="apple-touch-icon" sizes="180x180" href="/touch.png">'
                .'<link rel="icon" sizes="32x32" href="https://cdn.shop.example/medium.png">'
            )),
            'shop.example/touch.png' => Http::response(self::PNG, 200, ['Content-Type' => 'image/png']),
            '*' => Http::response('not here', 404),
        ]);

        $this->assertTrue(StoreLogoFetcher::new()->fetch($this->store));

        $this->assertSame('logos/'.$this->store->id.'.png', $this->store->fresh()->logo_path);
        Storage::disk(StoreLogoFetcher::DISK)->assertExists('logos/'.$this->store->id.'.png');
        Http::assertSent(fn ($request) => $request->url() === 'https://shop.example/touch.png');
    }

    public function test_falls_back_to_favicon_ico(): void
    {
        Http::fake([
            'shop.example/' => Http::response($this->homePage('')),
            'shop.example/favicon.ico' => Http::response("\x00\x00\x01\x00icon-data"),
        ]);

        $this->assertTrue(StoreLogoFetcher::new()->fetch($this->store));
        $this->assertSame('logos/'.$this->store->id.'.ico', $this->store->fresh()->logo_path);
    }

    public function test_svg_is_refused_even_with_an_image_png_header(): void
    {
        Http::fake([
            'shop.example/' => Http::response($this->homePage('<link rel="icon" href="/logo.png">')),
            'shop.example/logo.png' => Http::response('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 200, ['Content-Type' => 'image/png']),
            'shop.example/favicon.ico' => Http::response('', 404),
        ]);

        $this->assertFalse(StoreLogoFetcher::new()->fetch($this->store));
        $this->assertNull($this->store->fresh()->logo_path);
    }

    public function test_svg_links_are_skipped(): void
    {
        $icons = StoreLogoFetcher::new()->iconsFromHtml(
            '<link rel="icon" type="image/svg+xml" href="/a.png"><link rel="icon" href="/b.svg"><link rel="icon" href="/c.png">',
            'https://shop.example/',
        );

        $this->assertSame(['https://shop.example/c.png'], $icons);
    }

    public function test_too_large_file_is_refused(): void
    {
        config(['dipalert.logo_max_bytes' => 100]);
        Http::fake([
            'shop.example/' => Http::response($this->homePage('')),
            'shop.example/favicon.ico' => Http::response("\x00\x00\x01\x00".str_repeat('x', 200)),
        ]);

        $this->assertFalse(StoreLogoFetcher::new()->fetch($this->store));
    }

    public function test_private_address_is_refused(): void
    {
        Http::fake(['*' => Http::response(self::PNG)]);

        $this->assertNull(StoreLogoFetcher::new()->download('http://192.168.0.1/logo.png'));

        Http::assertNothingSent();
    }

    public function test_icon_link_to_a_private_address_is_not_downloaded(): void
    {
        $this->hostResolver->set('intranet.shop.example', ['10.0.0.5']);
        Http::fake([
            'shop.example/' => Http::response($this->homePage('<link rel="icon" sizes="64x64" href="http://intranet.shop.example/logo.png">')),
            'shop.example/favicon.ico' => Http::response('', 404),
            'intranet.shop.example/*' => Http::response(self::PNG),
        ]);

        $this->assertFalse(StoreLogoFetcher::new()->fetch($this->store));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'intranet'));
    }

    public function test_type_is_detected_from_the_first_bytes(): void
    {
        $this->assertSame('png', StoreLogoFetcher::detectType(self::PNG));
        $this->assertSame('jpg', StoreLogoFetcher::detectType("\xFF\xD8\xFF\xE0jpeg"));
        $this->assertSame('ico', StoreLogoFetcher::detectType("\x00\x00\x01\x00ico"));
        $this->assertSame('webp', StoreLogoFetcher::detectType('RIFF1234WEBPVP8 '));
        $this->assertNull(StoreLogoFetcher::detectType('<svg></svg>'));
        $this->assertNull(StoreLogoFetcher::detectType('GIF89a'));
    }

    public function test_new_store_queues_a_logo_fetch(): void
    {
        config(['dipalert.fetch_store_logos' => true]);
        Queue::fake();

        $store = Store::factory()->create();
        Store::factory()->create(['logo_path' => 'logos/manual.png']);

        Queue::assertPushed(FetchStoreLogoJob::class, 1);
        Queue::assertPushed(FetchStoreLogoJob::class, fn (FetchStoreLogoJob $job): bool => $job->store->is($store));
    }
}
