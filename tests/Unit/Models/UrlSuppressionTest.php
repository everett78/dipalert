<?php

namespace Tests\Unit\Models;

use App\Models\Product;
use App\Models\Store;
use App\Models\Url;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UrlSuppressionTest extends TestCase
{
    use RefreshDatabase;

    protected Product $product;

    protected Store $shop;

    protected Url $ceneoSource;

    protected Url $skapiecSource;

    protected function setUp(): void
    {
        parent::setUp();

        $this->product = Product::factory()->create();
        $this->shop = Store::factory()->create(['name' => 'Allegro', 'domains' => [['domain' => 'allegro.pl']]]);

        $ceneo = Store::factory()->create(['settings' => ['scraper_service' => 'http', 'comparison' => 'ceneo']]);
        $skapiec = Store::factory()->create(['settings' => ['scraper_service' => 'http', 'comparison' => 'skapiec']]);

        $this->ceneoSource = $this->link($ceneo);
        $this->skapiecSource = $this->link($skapiec);
    }

    protected function link(Store $store, ?Url $parent = null, ?string $outcome = 'ok'): Url
    {
        return Url::factory()->create([
            'product_id' => $this->product->id,
            'store_id' => $store->id,
            'parent_url_id' => $parent?->id,
            'comparison_key' => $parent ? 'allegro.pl' : null,
            'last_outcome' => $outcome,
        ]);
    }

    /**
     * @return list<int>
     */
    protected function counted(): array
    {
        return $this->product->fresh()->countedUrlIds();
    }

    public function test_direct_link_beats_ceneo_which_beats_skapiec(): void
    {
        $direct = $this->link($this->shop);
        $fromCeneo = $this->link($this->shop, $this->ceneoSource);
        $fromSkapiec = $this->link($this->shop, $this->skapiecSource);

        $this->assertSame([$direct->id], array_values(array_intersect($this->counted(), [$direct->id, $fromCeneo->id, $fromSkapiec->id])));
        $this->assertTrue($fromCeneo->fresh()->isSuppressed());

        // The direct link fails: Ceneo's line becomes visible, Skąpiec's stays hidden.
        $direct->update(['last_outcome' => 'blocked']);
        $this->assertSame([$direct->id, $fromCeneo->id], array_values(array_intersect($this->counted(), [$direct->id, $fromCeneo->id, $fromSkapiec->id])));

        // Ceneo's line left the list too: Skąpiec's line shows.
        $fromCeneo->update(['last_outcome' => 'no_price']);
        $this->assertContains($fromSkapiec->id, $this->counted());
        $this->assertNotContains($fromCeneo->id, $this->counted());
    }

    public function test_comparison_source_links_are_never_counted(): void
    {
        $this->assertNotContains($this->ceneoSource->id, $this->counted());
        $this->assertNotContains($this->skapiecSource->id, $this->counted());
    }

    public function test_two_direct_links_to_one_store_are_both_visible(): void
    {
        $first = $this->link($this->shop);
        $second = $this->link($this->shop);

        $this->assertContains($first->id, $this->counted());
        $this->assertContains($second->id, $this->counted());
    }

    public function test_a_skipped_direct_link_keeps_the_backup_hidden(): void
    {
        $this->link($this->shop, null, 'skipped');
        $fromCeneo = $this->link($this->shop, $this->ceneoSource);

        $this->assertNotContains($fromCeneo->id, $this->counted());
    }
}
