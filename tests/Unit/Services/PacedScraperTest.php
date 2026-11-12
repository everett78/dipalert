<?php

namespace Tests\Unit\Services;

use App\Dto\FetchContext;
use App\Enums\ScraperService;
use App\Exceptions\StoreBusyException;
use App\Exceptions\StorePausedException;
use App\Models\FetchPacing;
use App\Models\Store;
use App\Services\Helpers\SettingsHelper;
use App\Services\Scrapers\ScraperFactory;
use Carbon\CarbonInterval;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class PacedScraperTest extends TestCase
{
    use RefreshDatabase;

    protected const string URL = 'https://www.shop.example/product/1';

    protected const string HOST = 'shop.example';

    protected Store $store;

    protected function setUp(): void
    {
        parent::setUp();

        SettingsHelper::setSetting('scrape_schedule', '0 7,13,19 * * *');
        Carbon::setTestNow(Carbon::parse('2026-11-12 07:05:00'));

        $this->store = Store::factory()->create([
            'domains' => [['domain' => 'www.shop.example']],
            'settings' => ['scraper_service' => ScraperService::Http->value, 'min_interval_seconds' => 10],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    protected function fetch(FetchContext $ctx, string $url = self::URL): void
    {
        ScraperFactory::make(ScraperService::Http->value, $url, $this->store, $ctx)->get();
    }

    protected function fakeOk(): void
    {
        Http::fake(['*' => Http::response('<html>ok</html>', 200)]);
    }

    protected function fakeBlocked(): void
    {
        Http::fake(['*' => Http::response('blocked', 403)]);
    }

    protected function pacing(): FetchPacing
    {
        return FetchPacing::query()->where('host', self::HOST)->firstOrFail();
    }

    public function test_second_request_to_the_same_host_waits_for_the_interval(): void
    {
        $this->fakeOk();

        $this->fetch(FetchContext::run());
        Sleep::assertNeverSlept();

        $this->fetch(FetchContext::run());

        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn (CarbonInterval $duration): bool => (int) round($duration->totalSeconds) === 10);
        Http::assertSentCount(2);
    }

    public function test_interactive_fetch_that_would_wait_too_long_is_busy_without_a_request(): void
    {
        $this->store->settings = array_merge($this->store->settings, ['min_interval_seconds' => 60]);
        $this->store->save();
        $this->fakeOk();

        $this->fetch(FetchContext::run());

        try {
            $this->fetch(new FetchContext(\App\Enums\FetchSource::Panel, interactive: true));
            $this->fail('Expected StoreBusyException');
        } catch (StoreBusyException $e) {
            $this->assertSame(self::HOST, $e->host);
            $this->assertGreaterThan(15, $e->seconds);
        }

        Http::assertSentCount(1);
    }

    public function test_pause_levels_are_counted_in_runs(): void
    {
        $this->fakeBlocked();

        // 07:05, in a run: paused until the next run at 13:00.
        $this->fetch(FetchContext::run());
        $this->assertSame(1, $this->pacing()->pause_level);
        $this->assertSame('2026-11-12 13:00', $this->pacing()->paused_until->format('Y-m-d H:i'));

        // 13:02, blocked again: skips the 19:00 run, paused until 07:00 next day.
        Carbon::setTestNow(Carbon::parse('2026-11-12 13:02:00'));
        $this->fetch(FetchContext::run());
        $this->assertSame(2, $this->pacing()->pause_level);
        $this->assertSame('2026-11-13 07:00', $this->pacing()->paused_until->format('Y-m-d H:i'));

        // 07:03 next day, blocked a third time: a full day.
        Carbon::setTestNow(Carbon::parse('2026-11-13 07:03:00'));
        $this->fetch(FetchContext::run());
        $this->assertSame(3, $this->pacing()->pause_level);
        $this->assertSame('2026-11-14 07:03', $this->pacing()->paused_until->format('Y-m-d H:i'));
    }

    public function test_block_outside_a_run_pauses_for_an_hour(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-12 10:00:00'));
        $this->fakeBlocked();

        $this->fetch(FetchContext::panelQueued());

        $this->assertSame('2026-11-12 11:00', $this->pacing()->paused_until->format('Y-m-d H:i'));
    }

    public function test_a_response_that_is_not_a_block_resets_the_level(): void
    {
        FetchPacing::query()->create(['host' => self::HOST, 'pause_level' => 2]);
        $this->fakeOk();

        $this->fetch(FetchContext::run());

        $this->assertSame(0, $this->pacing()->pause_level);
        $this->assertNull($this->pacing()->paused_until);
    }

    public function test_a_network_error_does_not_reset_the_level(): void
    {
        FetchPacing::query()->create(['host' => self::HOST, 'pause_level' => 2]);
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $this->fetch(FetchContext::run());

        $this->assertSame(2, $this->pacing()->pause_level);
    }

    public function test_paused_store_is_skipped_without_a_request(): void
    {
        $this->fakeBlocked();
        $this->fetch(FetchContext::run());
        Http::assertSentCount(1);

        $this->expectException(StorePausedException::class);

        try {
            $this->fetch(FetchContext::run());
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_resume_lifts_the_pause_and_the_level(): void
    {
        $this->fakeBlocked();
        $this->fetch(FetchContext::run());

        FetchPacing::resume(self::HOST);

        $this->assertSame(0, $this->pacing()->pause_level);
        $this->assertNull($this->pacing()->paused_until);
        $this->assertSame([], FetchPacing::pausedHosts()->all());
    }

    public function test_state_survives_a_cache_flush(): void
    {
        $this->fakeBlocked();
        $this->fetch(FetchContext::run());

        Cache::flush();

        $this->expectException(StorePausedException::class);
        $this->fetch(FetchContext::run());
    }

    public function test_a_cache_hit_in_the_panel_does_not_wait(): void
    {
        $this->fakeOk();

        $this->fetch(FetchContext::panel());
        $this->fetch(FetchContext::panel());

        Http::assertSentCount(1);
        Sleep::assertNeverSlept();
    }

    public function test_a_run_does_not_use_the_page_cache(): void
    {
        $this->fakeOk();

        $this->fetch(FetchContext::panel());
        $this->fetch(FetchContext::run());

        Http::assertSentCount(2);
    }

    public function test_held_lock_in_interactive_mode_is_busy(): void
    {
        config(['dipalert.pacing_lock_wait_seconds' => 0]);
        $this->fakeOk();

        $lock = Cache::lock('pacing:'.self::HOST, 10);
        $this->assertTrue($lock->get());

        try {
            $this->expectException(StoreBusyException::class);
            $this->fetch(FetchContext::panel());
        } finally {
            $lock->release();
            Http::assertNothingSent();
        }
    }

    public function test_refused_address_takes_no_pacing_slot(): void
    {
        $this->hostResolver->set('www.shop.example', ['192.168.0.10']);
        $this->fakeOk();

        try {
            $this->fetch(FetchContext::run());
        } catch (\App\Exceptions\BlockedUrlException) {
            // expected
        }

        Http::assertNothingSent();
        $this->assertNull(FetchPacing::query()->where('host', self::HOST)->first());
    }
}
