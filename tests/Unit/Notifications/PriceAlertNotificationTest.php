<?php

namespace Tests\Unit\Notifications;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_mail_has_the_price_drop_subject()
    {
        $user = User::factory()->create();

        [, , $url] = $this->createStoreProductAndPrice('Test Store', 'Test Product', $user->getKey(), 99.99);

        $mail = (new PriceAlertNotification($url))->toMail($user);

        $this->assertEquals('Price drop: Test Product ($99.99)', $mail->subject);
    }

    public function test_to_array_contains_summary_and_buy_url()
    {
        $user = User::factory()->create();

        [, , $url] = $this->createStoreProductAndPrice('Test Store', 'Test Product', $user->getKey(), 99.99);

        $data = (new PriceAlertNotification($url))->toArray($user);

        $this->assertStringContainsString('Test Store has had a price drop for Test Product - $99.99', $data['summary']);
        $this->assertEquals('https://example.com/product', $data['buyUrl']);
    }

    public function test_only_mail_and_database_channels_are_used()
    {
        $user = User::factory()->create();

        [, , $url] = $this->createStoreProductAndPrice('Test Store', 'Test Product', $user->getKey(), 99.99);

        $channels = (new PriceAlertNotification($url))->via($user);

        $this->assertEmpty(array_diff($channels, ['mail', 'database']));
        $this->assertContains('database', $channels);
    }

    protected function createStoreProductAndPrice(string $storeName, string $productTitle, int $userId, float $price): array
    {
        $store = Store::factory()->create([
            'name' => $storeName,
            'settings' => [
                'locale_settings' => [
                    'locale' => 'en_US',
                    'currency' => 'USD',
                ],
            ],
        ]);

        $product = Product::factory()->create(['title' => $productTitle, 'user_id' => $userId]);
        $url = Url::factory()->for($store)->for($product)->create([
            'url' => 'https://example.com/product',
        ]);

        $url->prices()->create([
            'price' => $price,
            'store_id' => $store->id,
        ]);

        return [$store, $product, $url];
    }
}
