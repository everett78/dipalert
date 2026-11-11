<?php

namespace App\View\Components;

use App\Enums\Trend;
use App\Models\Product;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class RangeChart extends Component
{
    public array $cachedDatasets = [];

    public array $options = [];

    public string $type = 'line';

    public ?string $maxHeight = null;

    public string $color = 'gray';

    /**
     * Create a new component instance.
     */
    public function __construct(
        public Product $product,
        public string $height = '100px',
    ) {
        $this->parseCachedPrices();
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return view('components.range-chart');
    }

    public function parseCachedPrices(): void
    {
        $data = $this->product->getAggregateRange();

        $colorRgb = Trend::getColorRgb($this->product->trend)[400];

        $datasets = [
            [
                'label' => 'Min',
                'data' => array_values($data['min'] ?? []),
                'backgroundColor' => 'rgba('.$colorRgb.', 0.2)',
                'borderColor' => 'rgba('.$colorRgb.', 1)',
                'borderWidth' => 2,
                'tension' => 0.5,
                'pointHitRadius' => 0,
                'fill' => 1,
            ],
            [
                'label' => 'Avg',
                'data' => array_values($data['avg'] ?? []),
                'backgroundColor' => 'rgba(0, 0, 0, 0.4)',
                'borderColor' => 'rgba('.$colorRgb.', 0)',
                'borderWidth' => 3,
                'tension' => 0.5,
                'fill' => 1,
            ],
            [
                'label' => 'Max',
                'data' => array_values($data['max'] ?? []),
                'backgroundColor' => 'rgba('.$colorRgb.', 0.2)',
                'borderColor' => 'rgba(0, 0, 0, 0)',
                'borderWidth' => 1,
                'tension' => 0.5,
                'pointHitRadius' => 0,
                'fill' => 1,
            ],
        ];

        $this->cachedDatasets = [
            'datasets' => $datasets,
            'labels' => array_keys($data['avg'] ?? []),
        ];
    }
}
