<?php

namespace Tests\Unit\Models;

use App\Models\Price;
use App\Models\Product;
use App\Models\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProductThresholdTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    /**
     * @param  array<string, mixed>  $thresholds
     * @return array{0: Product, 1: Url, 2: Price}
     */
    protected function pricesFor(array $thresholds, ?float $previous, float $new): array
    {
        $product = Product::factory()->create(array_merge([
            'notify_price' => null,
            'notify_percent' => null,
            'notify_on_change' => false,
        ], $thresholds));
        $url = Url::factory()->create(['product_id' => $product->id]);

        if ($previous !== null) {
            $url->prices()->create(['price' => $previous, 'unit_price' => $previous, 'store_id' => $url->store_id]);
        }

        /** @var Price $price */
        $price = $url->prices()->create(['price' => $new, 'unit_price' => $new, 'store_id' => $url->store_id]);

        return [$product->fresh(), $url, $price];
    }

    public function test_percent_drop_reaching_the_threshold_notifies(): void
    {
        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 5], 100, 95);

        $this->assertTrue($product->shouldNotifyOnThreshold($url, $price));
    }

    public function test_percent_drop_below_the_threshold_does_not_notify(): void
    {
        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 5], 100, 96);

        $this->assertFalse($product->shouldNotifyOnThreshold($url, $price));
    }

    public function test_amount_threshold(): void
    {
        [$product, $url, $price] = $this->pricesFor(['notify_change_amount' => 50], 1000, 949);
        $this->assertTrue($product->shouldNotifyOnThreshold($url, $price));

        // Only the amount set: a small change sends nothing.
        [$product, $url, $price] = $this->pricesFor(['notify_change_amount' => 50], 1000, 990);
        $this->assertFalse($product->shouldNotifyOnThreshold($url, $price));
    }

    public function test_either_threshold_is_enough(): void
    {
        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 50, 'notify_change_amount' => 10], 100, 89);

        $this->assertTrue($product->shouldNotifyOnThreshold($url, $price));
    }

    public function test_default_direction_is_down(): void
    {
        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 5], 100, 120);

        $this->assertFalse($product->shouldNotifyOnThreshold($url, $price));
    }

    public function test_up_and_both_directions(): void
    {
        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 5, 'notify_change_direction' => 'up'], 100, 120);
        $this->assertTrue($product->shouldNotifyOnThreshold($url, $price));

        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 5, 'notify_change_direction' => 'up'], 100, 80);
        $this->assertFalse($product->shouldNotifyOnThreshold($url, $price));

        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 5, 'notify_change_direction' => 'both'], 100, 80);
        $this->assertTrue($product->shouldNotifyOnThreshold($url, $price));

        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 5, 'notify_change_direction' => 'both'], 100, 120);
        $this->assertTrue($product->shouldNotifyOnThreshold($url, $price));
    }

    public function test_no_previous_price_does_not_notify(): void
    {
        [$product, $url, $price] = $this->pricesFor(['notify_change_percent' => 5], null, 50);

        $this->assertFalse($product->shouldNotifyOnThreshold($url, $price));
    }

    public function test_rule_is_off_without_thresholds(): void
    {
        [$product, $url, $price] = $this->pricesFor([], 100, 10);

        $this->assertFalse($product->shouldNotifyOnThreshold($url, $price));
    }

    public function test_no_change_does_not_notify(): void
    {
        [$product, $url, $price] = $this->pricesFor(['notify_change_amount' => 0.01, 'notify_change_direction' => 'both'], 100, 100);

        $this->assertFalse($product->shouldNotifyOnThreshold($url, $price));
    }
}
