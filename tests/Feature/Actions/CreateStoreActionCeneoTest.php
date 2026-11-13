<?php

namespace Tests\Feature\Actions;

use App\Actions\CreateStoreAction;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateStoreActionCeneoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_ceneo_store_is_a_comparison_site(): void
    {
        $store = (new CreateStoreAction)([
            'name' => 'Ceneo',
            'domains' => [['domain' => 'ceneo.pl'], ['domain' => 'www.ceneo.pl']],
            'scrape_strategy' => [],
            'settings' => ['scraper_service' => 'flaresolverr'],
        ]);

        $this->assertSame(Store::COMPARISON_CENEO, $store->comparison);
        $this->assertSame('http', $store->scraper_service);
    }

    public function test_other_stores_are_not(): void
    {
        $store = (new CreateStoreAction)([
            'name' => 'Morele',
            'domains' => [['domain' => 'morele.net']],
            'scrape_strategy' => [],
            'settings' => [],
        ]);

        $this->assertNull($store->comparison);
    }
}
