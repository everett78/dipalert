<?php

namespace App\Filament\Resources\CollectionResource\RelationManagers;

use App\Actions\ApplyCollectionTemplateAction;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProductsRelationManager extends RelationManager
{
    protected static string $relationship = 'products';

    protected static ?string $recordTitleAttribute = 'title';

    public static function getTitle(\Illuminate\Database\Eloquent\Model $ownerRecord, string $pageClass): string
    {
        return __('Products');
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('title')
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('Product'))
                    ->limit(80)
                    ->url(fn (Product $record): string => $record->view_url)
                    ->searchable(),
                Tables\Columns\TextColumn::make('current_price')
                    ->label(__('Price'))
                    ->color('gray'),
            ])
            ->headerActions([
                // Attaching a product copies the collection template into it.
                Tables\Actions\AttachAction::make()
                    ->label(__('Attach'))
                    ->multiple()
                    ->preloadRecordSelect()
                    ->recordSelectOptionsQuery(fn (Builder $query) => $query->where('products.user_id', auth()->id()))
                    ->after(function (array $data): void {
                        /** @var ProductCollection $collection */
                        $collection = $this->getOwnerRecord();
                        $action = new ApplyCollectionTemplateAction;

                        Product::query()
                            ->whereIn('id', (array) ($data['recordId'] ?? []))
                            ->get()
                            ->each(fn (Product $product) => $action->execute($collection, $product));
                    }),
            ])
            ->actions([
                Tables\Actions\DetachAction::make()->label(__('Detach')),
            ])
            ->bulkActions([
                Tables\Actions\DetachBulkAction::make()->label(__('Detach')),
            ]);
    }
}
