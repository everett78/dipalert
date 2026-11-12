<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ProductResource\Pages\ViewProduct;
use App\Filament\Resources\ProductResource\Widgets\PriceHistoryChart;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionMethod;
use Tests\TestCase;

class PriceHistoryChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_chart_includes_a_best_price_dataset(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50])
            ->create();

        $this->assertNotNull($this->dataset($this->chartData($product), 'Najniższa cena'));
    }

    public function test_chart_includes_target_line_when_notify_price_is_set(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://example-a.com/p', [60, 55, 50])
            ->create(['notify_price' => 45.00]);

        Livewire::test(PriceHistoryChart::class, ['record' => $product])
            ->assertSee('Your target');
    }

    /**
     * @return array<string, mixed>
     */
    protected function chartData(Product $product, string $filter = 'retail_price'): array
    {
        $chart = Livewire::test(PriceHistoryChart::class, ['record' => $product->fresh()])
            ->set('filter', $filter)
            ->instance();

        $method = new ReflectionMethod($chart, 'getData');

        return $method->invoke($chart);
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataset(array $data, string $label): array
    {
        return collect($data['datasets'])->firstWhere('label', $label);
    }

    public function test_best_price_uses_only_the_chosen_stores(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://store-a.example/p', [60, 55, 50])
            ->addUrlWithPrices('https://store-b.example/p', [40, 41, 42])
            ->create(['notify_price' => null]);

        $storeA = $product->urls()->with('store')->get()->first(fn ($url) => str_contains($url->url, 'store-a'))->store;
        $product->update(['chart_settings' => ['stores' => [$storeA->id], 'period' => 30]]);

        $data = $this->chartData($product);

        $this->assertCount(2, $data['datasets'], 'One store line and the lowest price line');
        $best = array_filter($this->dataset($data, 'Najniższa cena')['data'], fn ($value) => $value !== null);
        $this->assertGreaterThanOrEqual(50, min($best));
    }

    public function test_empty_store_selection_shows_all_stores(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://store-a.example/p', [60, 55, 50])
            ->addUrlWithPrices('https://store-b.example/p', [40, 41, 42])
            ->create(['chart_settings' => ['stores' => [], 'period' => 30], 'notify_price' => null]);

        $data = $this->chartData($product);

        $this->assertCount(3, $data['datasets']);
        $best = array_filter($this->dataset($data, 'Najniższa cena')['data'], fn ($value) => $value !== null);
        $this->assertSame(40.0, (float) min($best));
    }

    public function test_period_of_seven_days_and_all_history(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://store-a.example/p', range(500, 101))
            ->create();

        $product->update(['chart_settings' => ['stores' => [], 'period' => 7]]);
        $this->assertLessThanOrEqual(8, count($this->chartData($product)['labels']));
        $this->assertLessThanOrEqual(8, count($this->chartData($product, 'unit_price')['labels']));

        // "All" includes prices older than a year.
        $product->update(['chart_settings' => ['stores' => [], 'period' => 'all']]);
        $this->assertGreaterThan(366, count($this->chartData($product)['labels']));

        // The default is a year.
        $product->update(['chart_settings' => null]);
        $this->assertLessThanOrEqual(367, count($this->chartData($product, 'unit_price')['labels']));
    }

    public function test_line_colours_come_from_the_palette(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://store-a.example/p', [60, 55, 50])
            ->create();

        $data = $this->chartData($product);
        $palette = config('dipalert.chart_palette');

        $this->assertCount(20, $palette);
        $this->assertSame('rgba('.$palette[0].', 0.9)', $data['datasets'][0]['borderColor']);
        $this->assertSame($palette[19], PriceHistoryChart::datasetColor(19));
    }

    public function test_chart_settings_form_saves_the_choice(): void
    {
        $product = Product::factory()
            ->addUrlWithPrices('https://store-a.example/p', [60, 55, 50])
            ->create(['user_id' => auth()->id()]);
        $storeId = $product->urls()->first()->store_id;

        Livewire::test(ViewProduct::class, ['record' => $product->getRouteKey()])
            ->set('chartSettings.period', 'all')
            ->assertDispatched('chart-settings-updated')
            ->set('chartSettings.stores', [(string) $storeId])
            ->assertDispatched('chart-settings-updated');

        $this->assertSame(['stores' => [$storeId], 'period' => 'all'], $product->fresh()->chart_settings);
    }
}
