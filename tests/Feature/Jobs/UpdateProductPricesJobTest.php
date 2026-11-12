<?php

namespace Tests\Feature\Jobs;

use App\Dto\FetchContext;
use App\Jobs\RetryUrlPriceJob;
use App\Jobs\UpdateProductPricesJob;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\ScrapeFailNotification;
use App\Settings\AppSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class UpdateProductPricesJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        Sleep::fake();
    }

    public function test_job_updates_product_prices_without_a_fixed_pause(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Test Product']);

        (new UpdateProductPricesJob($product->id, FetchContext::run()))->handle();

        // The fixed pause after every product is gone; the pacing proxy keeps
        // the interval per store instead.
        Sleep::assertNeverSlept();
    }

    public function test_job_logs_when_logging_enabled(): void
    {
        Log::spy();

        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'Test Product']);

        (new UpdateProductPricesJob($product->id, FetchContext::run(), true))->handle();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message) => str_contains($message, "Starting price fetch for: 'Test Product'"))
            ->once();
    }

    public function test_job_schedules_retries_for_network_errors_and_suppresses_notification(): void
    {
        Queue::fake();
        Notification::fake();
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $product = Product::factory()->create();
        $failUrl = Url::factory()->create(['product_id' => $product->id, 'url' => 'https://example.com/item']);

        (new UpdateProductPricesJob($product->id, FetchContext::run()))->handle();

        Notification::assertNothingSent();
        Queue::assertPushed(
            RetryUrlPriceJob::class,
            fn (RetryUrlPriceJob $job) => $job->url->is($failUrl) && $job->attempt === 2 && $job->delay !== null
        );
    }

    public function test_job_notifies_immediately_when_retries_disabled(): void
    {
        Queue::fake();
        Notification::fake();
        Http::fake(['*' => Http::failedConnection('Connection refused')]);

        $settings = AppSettings::new();
        $settings->scrape_retry_max_attempts = 1;
        $settings->save();

        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);
        Url::factory()->create(['product_id' => $product->id, 'url' => 'https://example.com/item']);

        (new UpdateProductPricesJob($product->id, FetchContext::run()))->handle();

        Notification::assertSentTo($user, ScrapeFailNotification::class);
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }

    public function test_job_does_not_send_notification_on_success(): void
    {
        Queue::fake();
        Notification::fake();

        $user = User::factory()->create();
        $product = Product::factory()->create(['user_id' => $user->id]);

        (new UpdateProductPricesJob($product->id, FetchContext::run()))->handle();

        Notification::assertNothingSent();
        Queue::assertNotPushed(RetryUrlPriceJob::class);
    }
}
