<?php

namespace App\Filament\Resources\ProductResource\Widgets;

use App\Models\Product;
use App\Models\Url;
use App\Rules\StoreUrl;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UrlsTableWidget extends BaseWidget
{
    public Model|Product|null $record = null;

    /**
     * "zapas" for a hidden line, "brak na liście od <date>" for an offer that
     * left the list, "porównywarka" for the comparison link itself.
     */
    protected static function lineBadges(Url $url, Product $product): string
    {
        $badges = [];

        if ($url->isComparisonSource()) {
            $badges[] = __('comparison site');
        } elseif ($url->isComparisonChild() && ! $url->lastReadWorks()) {
            $badges[] = __('not on the list since :date', ['date' => $url->last_seen_at?->format('Y-m-d H:i') ?? '—']);
        } elseif ($url->isSuppressed(Url::query()->with(['store', 'parent.store'])->where('product_id', $product->getKey())->get())) {
            $badges[] = __('backup');
        }

        return collect($badges)
            ->map(fn (string $badge): string => ' <span class="ml-2 rounded bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600 dark:bg-white/10 dark:text-gray-300">'.e($badge).'</span>')
            ->implode('');
    }

    public function table(Table $table): Table
    {
        /** @var Product $product */
        $product = $this->record;

        return $table
            ->heading('Product Urls')
            // Offer lines right under their comparison link.
            ->query(
                $product->urls()->with(['store', 'parent.store'])->getQuery()
                    ->orderByRaw('COALESCE(urls.parent_url_id, urls.id)')
                    ->orderByRaw('urls.parent_url_id IS NOT NULL')
                    ->orderBy('urls.id')
            )
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Stack::make([
                        Tables\Columns\TextColumn::make('store.name')
                            ->label('Store')
                            ->html()
                            ->formatStateUsing(fn ($state, Url $record): HtmlString => new HtmlString(
                                ($record->isComparisonChild() ? '<span class="text-gray-400 pl-4">└ </span>' : '')
                                .view('components.store-logo', ['store' => $record->store, 'withName' => true])->render()
                                .self::lineBadges($record, $product)
                            )),
                        Tables\Columns\TextColumn::make('url')
                            ->label('Url')
                            ->color('gray')
                            ->formatStateUsing(fn (string $state, Url $record): HtmlString => new HtmlString(
                                $record->isComparisonChild()
                                    ? '<span class="text-xs">'.e(__('Offer from :site', ['site' => $record->parent->store->name ?? 'Ceneo'])).'</span>'
                                    : '<a href="'.e($state).'" title="'.e($state).'" target="_blank">'.e(Str::limit($state, 80)).'</a>'
                            )),
                    ]),
                    Tables\Columns\TextColumn::make('price_factor')
                        ->label('Price Factor')
                        ->grow(false)
                        ->badge()
                        ->color('gray'),
                ]),

            ])
            ->actions([
                Tables\Actions\EditAction::make()
                    ->form([
                        TextInput::make('url')
                            ->label('URL')
                            ->required()
                            ->rules([new StoreUrl]),
                        TextInput::make('price_factor')
                            ->label('Price Factor')
                            ->numeric()
                            ->default(1)
                            ->minValue(0.01)
                            ->required(),
                    ])
                    ->using(function (Url $record, array $data): Url {
                        // Validate/apply the URL change first so a failed change leaves
                        // nothing persisted (changeUrl fails closed) before price_factor.
                        if (trim($data['url']) !== $record->url && ! $record->changeUrl($data['url'])) {
                            throw ValidationException::withMessages([
                                'url' => __('Unable to resolve a store or price for this URL'),
                            ]);
                        }

                        $record->price_factor = (float) $data['price_factor'];
                        $record->save();

                        return $record;
                    })
                    ->after(function (Url $record) {
                        $record->syncStoredPricesForCurrentFactor();
                        $record->product->updatePriceCache();

                        Notification::make('url_updated')
                            ->title('URL updated')
                            ->success()
                            ->send();
                    }),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }
}
