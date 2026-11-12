<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Models\Product;
use App\Models\Url;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets\ChartWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;

/**
 * @property Product $record
 */
class PriceHistoryChart extends ChartWidget
{
    public const string PERIOD_ALL = 'all';

    /**
     * Periods offered above the chart (days, or "all").
     */
    public const array PERIODS = [7, 30, 90, 365, self::PERIOD_ALL];

    public const int DEFAULT_PERIOD = 365;

    public Model|Product|null $record = null;

    protected static ?string $maxHeight = '300px';

    protected static ?string $heading = 'Store price history';

    protected static ?string $pollingInterval = null;

    public ?string $filter = 'unit_price';

    protected function getFilters(): ?array
    {
        return [
            'unit_price' => 'Unit Price',
            'retail_price' => 'Retail Price',
        ];
    }

    /**
     * The chart settings changed above the chart: reload them and redraw.
     */
    #[On('chart-settings-updated')]
    public function refreshChart(): void
    {
        $this->record->refresh();
        $this->cachedData = null;
    }

    /**
     * Days of history to show (null = all), from the product chart settings.
     */
    public static function periodDays(Product $product): ?int
    {
        $period = data_get($product->chart_settings, 'period', self::DEFAULT_PERIOD);

        if ($period === self::PERIOD_ALL) {
            return null;
        }

        return in_array((int) $period, self::PERIODS, true) ? (int) $period : self::DEFAULT_PERIOD;
    }

    /**
     * Store ids chosen for the chart; empty = all stores.
     *
     * @return list<int>
     */
    public static function selectedStores(Product $product): array
    {
        return array_values(array_map('intval', (array) data_get($product->chart_settings, 'stores', [])));
    }

    /**
     * Retail price with a period of up to a year comes from the price cache (as
     * before), cut to the period. "All" or the unit price is read directly.
     *
     * @return Collection<int|string, mixed>
     */
    protected function history(): Collection
    {
        $days = self::periodDays($this->record);
        $column = $this->filter === 'unit_price' ? 'unit_price' : 'price';

        if ($column === 'price' && $days !== null) {
            $from = Carbon::now()->subDays($days)->toDateString();

            return $this->record->getPriceHistoryCached('price')
                ->map(fn (Collection $prices) => $prices->filter(fn ($price, string $date): bool => $date >= $from));
        }

        return $this->record->getPriceHistory($column, $days);
    }

    protected function getData(): array
    {
        $history = $this->history();
        $stores = self::selectedStores($this->record);

        $urls = Url::with('store')->findMany($history->keys())
            ->filter(fn (Url $url): bool => $stores === [] || in_array((int) $url->store_id, $stores, true))
            ->values();

        // Only the chosen lines, so "Najniższa cena" uses the same stores.
        $history = $history->only($urls->pluck('id')->all())
            ->filter(fn (Collection $prices): bool => $prices->isNotEmpty());

        $datasets = [];

        foreach ($urls as $idx => $url) {
            if (! $history->has($url->id)) {
                continue;
            }

            $color = self::datasetColor($idx);

            $datasets[] = [
                'label' => $url->store?->name,
                'data' => $history->get($url->id),
                'backgroundColor' => 'rgba('.$color.', 0.4)',
                'borderColor' => 'rgba('.$color.', 0.9)',
                'fill' => true,
                'tension' => 0.2,
            ];
        }

        $labels = $this->getLabels($history);

        // Daily best = lowest price across the chosen stores per day, using the
        // SAME column ($history respects the unit/retail filter).
        $bestByDate = [];
        foreach ($history as $urlHistory) {
            foreach ($urlHistory as $date => $price) {
                if ($price <= 0) {
                    continue;
                }
                $bestByDate[$date] = isset($bestByDate[$date]) ? min($bestByDate[$date], $price) : $price;
            }
        }

        $datasets[] = [
            'label' => 'Najniższa cena',
            'data' => collect($labels)->map(fn (string $date) => $bestByDate[$date] ?? null)->all(),
            'borderColor' => 'rgba('.AdminPanelProvider::PRIMARY_COLOR[600].', 1)',
            'backgroundColor' => 'rgba('.AdminPanelProvider::PRIMARY_COLOR[600].', 0)',
            'borderWidth' => 3,
            'tension' => 0.2,
            'fill' => false,
            'spanGaps' => true,
        ];

        // Flat target line when a notify price is configured.
        if ($this->record->notify_price !== null) {
            $target = (float) $this->record->notify_price;
            $datasets[] = [
                'label' => 'Your target',
                'data' => array_fill(0, count($labels), $target),
                'borderColor' => 'rgba(239, 68, 68, 0.9)',
                'backgroundColor' => 'rgba(0, 0, 0, 0)',
                'borderWidth' => 1,
                'borderDash' => [5, 5],
                'pointRadius' => 0,
                'fill' => false,
            ];
        }

        return [
            'datasets' => $datasets,
            'labels' => $labels,
        ];
    }

    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => [
                    'type' => 'timeseries',
                    'ticks' => [
                        'stepSize' => 5,
                    ],
                    'time' => [
                        'unit' => 'day',
                    ],
                ],
                'y' => [
                    'type' => 'linear',
                    'ticks' => [
                        'count' => 5,
                    ],
                ],
            ],
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }

    protected function getLabels(Collection $history): array
    {
        return $history->map(fn ($prices) => collect($prices)->keys())
            ->flatten()
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }

    /**
     * Line colour (RGB) from the palette in config/dipalert.php.
     */
    public static function datasetColor(int $idx): string
    {
        $palette = (array) config('dipalert.chart_palette', []);

        return $palette[$idx] ?? Color::Gray[500];
    }
}
