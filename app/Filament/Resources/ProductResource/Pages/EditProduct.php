<?php

namespace App\Filament\Resources\ProductResource\Pages;

use App\Enums\Icons;
use App\Filament\Actions\BaseAction;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Widgets\UrlsTableWidget;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

/**
 * @property Product $record
 */
class EditProduct extends EditRecord
{
    protected static string $resource = ProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ProductResource\Actions\AddUrlAction::make(),
            BaseAction::make('view')->icon(Icons::View->value)
                ->label(__('View'))
                ->resourceName('product')
                ->resourceUrl('view', $this->record),
            ProductResource::deleteWithNameAction(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['collection_ids'] = $this->record->collections()->pluck('collections.id')->map(fn ($id): string => (string) $id)->all();

        return $data;
    }

    /**
     * Collections are synced in afterSave(), not saved as a column.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['collection_ids']);

        return $data;
    }

    /**
     * A product in two or more collections: changing a threshold or an
     * attribute changes it in all of them, so ask first.
     */
    protected function getSaveFormAction(): Actions\Action
    {
        return parent::getSaveFormAction()
            ->submit(null)
            ->requiresConfirmation(fn (): bool => $this->changesSharedSettings())
            ->modalHeading(__('Change in all collections'))
            ->modalDescription(fn (): string => __('This change applies to the product in all collections: :names', [
                'names' => $this->record->collections()->with('parent')->get()
                    ->map(fn (ProductCollection $collection): string => $collection->fullName())
                    ->implode(', '),
            ]))
            ->action(fn () => $this->save());
    }

    public function changesSharedSettings(): bool
    {
        if ($this->record->collections()->count() < 2) {
            return false;
        }

        foreach (['notify_change_percent', 'notify_change_amount', 'notify_change_direction'] as $field) {
            $old = $this->record->getAttribute($field);
            $new = data_get($this->data, $field);

            if ((string) ($old ?? '') !== (string) ($new ?? '')) {
                return is_numeric($old) && is_numeric($new) ? (float) $old !== (float) $new : true;
            }
        }

        $oldAttributes = (array) ($this->record->getAttribute('attributes') ?? []);
        $newAttributes = (array) (data_get($this->data, 'attributes') ?? []);
        ksort($oldAttributes);
        ksort($newAttributes);

        return array_map('strval', $oldAttributes) !== array_map('strval', $newAttributes);
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getFooterWidgets(): array
    {
        return [
            UrlsTableWidget::class,
        ];
    }

    public function getFooterWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function afterSave(): void
    {
        /** @var Product $product */
        $product = Product::find($this->record->getKey());

        $product->tags()->sync($this->data['tags']);

        ProductResource::syncCollections($product, (array) ($this->data['collection_ids'] ?? []));

        $product->updatePriceCache();
    }
}
