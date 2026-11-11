<?php

namespace Tests\Unit\Notifications;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\StockAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAlertNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_to_mail_has_the_back_in_stock_subject()
    {
        [$user, $url] = $this->createStoreProductAndUrl('Test Store', 'Test Product');

        $mail = (new StockAlertNotification($url))->toMail($user);

        $this->assertEquals('Back in stock: Test Product', $mail->subject);
    }

    public function test_database_array_contains_product_details()
    {
        [$user, $url] = $this->createStoreProductAndUrl('Test Store', 'Test Product');

        $data = (new StockAlertNotification($url))->toArray($user);

        $this->assertEquals('Back in stock: Test Product', $data['title']);
        $this->assertEquals('Test Product', $data['productName']);
        $this->assertEquals('Test Store', $data['storeName']);
        $this->assertStringContainsString('Test Store has Test Product back in stock', $data['summary']);
    }

    /**
     * @return array{0: User, 1: Url}
     */
    protected function createStoreProductAndUrl(string $storeName, string $productTitle): array
    {
        $user = User::factory()->create();

        $store = Store::factory()->create([
            'name' => $storeName,
            'settings' => [
                'locale_settings' => [
                    'locale' => 'en_US',
                    'currency' => 'USD',
                ],
            ],
        ]);

        $product = Product::factory()->create(['title' => $productTitle, 'user_id' => $user->getKey()]);
        $url = Url::factory()->for($store)->for($product)->create([
            'url' => 'https://example.com/product',
        ]);

        return [$user, $url];
    }
}
