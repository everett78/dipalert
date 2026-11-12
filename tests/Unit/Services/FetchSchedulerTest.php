<?php

namespace Tests\Unit\Services;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use App\Models\User;
use App\Services\FetchScheduler;
use App\Services\PriceFetcherService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FetchSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_alternates_between_stores(): void
    {
        $user = User::factory()->create();
        $a = Store::factory()->create(['name' => 'A']);
        $b = Store::factory()->create(['name' => 'B']);
        $c = Store::factory()->create(['name' => 'C']);

        $ids = [];
        foreach ([$a, $a, $a, $b, $c] as $i => $store) {
            $product = Product::factory()->create(['user_id' => $user->id, 'title' => 'P'.$i]);
            Url::factory()->create(['product_id' => $product->id, 'store_id' => $store->id, 'url' => 'https://example.com/'.$i]);
            $ids[] = $product->id;
        }

        $products = Product::query()->whereIn('id', $ids)->orderBy('id')->with('urls')->get();

        // A, A, A, B, C → A, B, C, A, A
        $this->assertSame([$ids[0], $ids[3], $ids[4], $ids[1], $ids[2]], FetchScheduler::order($products));
    }

    public function test_get_products_keeps_the_order_of_ids(): void
    {
        $user = User::factory()->create();
        $first = Product::factory()->create(['user_id' => $user->id]);
        $second = Product::factory()->create(['user_id' => $user->id]);
        $third = Product::factory()->create(['user_id' => $user->id]);

        $order = [$third->id, $first->id, $second->id];

        $this->assertSame($order, PriceFetcherService::new()->getProducts($order)->pluck('id')->all());
    }
}
