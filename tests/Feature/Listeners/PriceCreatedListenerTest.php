<?php

namespace Tests\Feature\Listeners;

use App\Enums\StockStatus;
use App\Models\Product;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PriceCreatedListenerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->user = User::factory()->create();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function urlFor(array $attributes): Url
    {
        $product = Product::factory()->create(array_merge([
            'user_id' => $this->user->id,
            'notify_price' => null,
            'notify_percent' => null,
            'notify_on_change' => false,
        ], $attributes));

        return Url::factory()->create(['product_id' => $product->id]);
    }

    protected function addPrice(Url $url, float $price): void
    {
        $url->prices()->create(['price' => $price, 'unit_price' => $price, 'store_id' => $url->store_id]);
    }

    public function test_same_price_sends_nothing(): void
    {
        $url = $this->urlFor(['notify_change_amount' => 1, 'notify_change_direction' => 'both', 'notify_on_change' => true]);

        $this->addPrice($url, 100);
        $this->addPrice($url, 100);

        Notification::assertNothingSent();
    }

    public function test_threshold_and_every_change_send_one_e_mail(): void
    {
        $url = $this->urlFor(['notify_change_percent' => 5, 'notify_on_change' => true, 'notify_price' => 1000]);

        $this->addPrice($url, 2000);
        Notification::assertNothingSent();

        // The drop matches the threshold, "every change" and the target price: one e-mail.
        $this->addPrice($url, 900);

        Notification::assertSentToTimes($this->user, PriceAlertNotification::class, 1);
        $this->assertTrue((bool) $url->prices()->latest('id')->first()->notified);
    }

    public function test_threshold_alone_notifies(): void
    {
        $url = $this->urlFor(['notify_change_amount' => 50]);

        $this->addPrice($url, 500);
        $this->addPrice($url, 440);

        Notification::assertSentToTimes($this->user, PriceAlertNotification::class, 1);
    }

    public function test_unavailable_link_sends_nothing(): void
    {
        $url = $this->urlFor(['notify_change_amount' => 1]);
        $url->forceFill(['availability' => StockStatus::OutOfStock])->save();

        $this->addPrice($url, 500);
        $this->addPrice($url, 100);

        Notification::assertNothingSent();
    }

    public function test_price_alert_goes_through_the_queue_with_retries(): void
    {
        $url = $this->urlFor([]);
        $notification = new PriceAlertNotification($url);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
        $this->assertSame(3, $notification->tries);
        $this->assertSame(300, $notification->backoff);
    }
}
