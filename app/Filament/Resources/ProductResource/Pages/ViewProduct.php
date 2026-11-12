<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\Icons;
use App\Filament\Actions\BaseAction;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\PriceHistoryChart;
use App\Models\Product;
use App\Models\Store;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;

/**
 * @property Product $record
 * @property Form $chartSettingsForm
 */
class ViewProduct extends ViewRecord
{
    protected static string $resource = ProductResource::class;

    protected static string $view = 'filament.resources.product-resource.pages.view';

    /**
     * Chart settings form state: stores and period.
     *
     * @var array<string, mixed>
     */
    public ?array $chartSettings = [];

    public function mount(int|string $record): void
    {
        parent::mount($record);

        $this->chartSettingsForm->fill([
            'stores' => PriceHistoryChart::selectedStores($this->record),
            'period' => (string) (PriceHistoryChart::periodDays($this->record) ?? PriceHistoryChart::PERIOD_ALL),
        ]);
    }

    /**
     * @return array<int|string, string|Form>
     */
    protected function getForms(): array
    {
        return array_merge(parent::getForms(), ['chartSettingsForm']);
    }

    /**
     * Store and period filter above the price chart, saved in the product.
     */
    public function chartSettingsForm(Form $form): Form
    {
        return $form
            ->statePath('chartSettings')
            ->columns(['sm' => 3])
            ->schema([
                Select::make('stores')
                    ->label(__('Stores'))
                    ->multiple()
                    ->allowHtml()
                    ->placeholder(__('All stores'))
                    ->options(fn (): array => $this->chartStoreOptions())
                    ->live()
                    ->afterStateUpdated(fn () => $this->saveChartSettings())
                    ->columnSpan(['sm' => 2]),
                Select::make('period')
                    ->label(__('Period'))
                    ->options([
                        '7' => __('7 days'),
                        '30' => __('30 days'),
                        '90' => __('90 days'),
                        '365' => __('365 days'),
                        PriceHistoryChart::PERIOD_ALL => __('All'),
                    ])
                    ->selectablePlaceholder(false)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn () => $this->saveChartSettings()),
            ]);
    }

    /**
     * Stores of this product's links, with their logos.
     *
     * @return array<int, string>
     */
    protected function chartStoreOptions(): array
    {
        $storeIds = $this->record->urls()->pluck('store_id')->filter()->unique()->all();

        return Store::query()->whereIn('id', $storeIds)->orderBy('name')->get()
            ->mapWithKeys(fn (Store $store): array => [
                $store->getKey() => view('components.store-logo', ['store' => $store, 'withName' => true])->render(),
            ])
            ->all();
    }

    public function saveChartSettings(): void
    {
        $state = (array) $this->chartSettings;
        $period = (string) ($state['period'] ?? PriceHistoryChart::DEFAULT_PERIOD);

        $this->record->update([
            'chart_settings' => [
                'stores' => array_values(array_map('intval', (array) ($state['stores'] ?? []))),
                'period' => $period === PriceHistoryChart::PERIOD_ALL ? PriceHistoryChart::PERIOD_ALL : (int) $period,
            ],
        ]);

        $this->dispatch('chart-settings-updated');
    }

    public function getTitle(): string|Htmlable
    {
        return $this->record->title;
    }

    /**
     * Scraped titles run long, so clamp the heading to a couple of lines rather than letting
     * it push the header actions down the page. The full title stays available on hover.
     */
    public function getHeading(): string|Htmlable
    {
        return new HtmlString(sprintf(
            '<span class="block max-w-3xl line-clamp-2 md:line-clamp-3" title="%s">%s</span>',
            e($this->record->title),
            e($this->record->title),
        ));
    }

    protected function getHeaderActions(): array
    {
        return [
            ProductResource\Actions\AddUrlAction::make(),
            ProductResource\Actions\FetchAction::make(),
            BaseAction::make('edit_product')->icon(Icons::Edit->value)
                ->label(__('Edit'))
                ->resourceName('product')
                ->resourceUrl('edit', $this->record),
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }
}
