<?php

namespace Tests\Unit\Notifications;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Notifications\PriceAlertNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;
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

    public function test_store_logo_is_embedded_in_the_e_mail()
    {
        Storage::fake('public');
        Storage::disk('public')->put('logos/1.png', "\x89PNG\r\n\x1a\nlogo");

        $user = User::factory()->create();
        [$store, , $url] = $this->createStoreProductAndPrice('Test Store', 'Test Product', $user->getKey(), 99.99);
        $store->update(['logo_path' => 'logos/1.png']);

        $data = (new PriceAlertNotification($url->fresh()))->toArray($user);
        $this->assertSame(Storage::disk('public')->path('logos/1.png'), $data['storeLogoPath']);

        $message = new Message(new Email);
        $html = (string) app(Markdown::class)->render('mail.price-change-notification', array_merge($data, ['message' => $message]));

        $this->assertStringContainsString('cid:', $html);
        $this->assertCount(1, $message->getSymfonyMessage()->getAttachments());
    }

    public function test_e_mail_without_a_logo_shows_the_store_name()
    {
        $user = User::factory()->create();
        [, , $url] = $this->createStoreProductAndPrice('Test Store', 'Test Product', $user->getKey(), 99.99);

        $data = (new PriceAlertNotification($url))->toArray($user);
        $html = (string) app(Markdown::class)->render('mail.price-change-notification', $data);

        $this->assertNull($data['storeLogoPath']);
        $this->assertStringNotContainsString('cid:', $html);
        $this->assertStringContainsString('Test Store', $html);
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
