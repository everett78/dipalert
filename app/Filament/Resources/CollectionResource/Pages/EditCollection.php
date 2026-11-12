<?php

namespace App\Filament\Resources\CollectionResource\Pages;

use App\Actions\DeleteCollectionAction;
use App\Filament\Resources\CollectionResource;
use App\Models\Collection as ProductCollection;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

/**
 * @property ProductCollection $record
 */
class EditCollection extends EditRecord
{
    protected static string $resource = CollectionResource::class;

    /**
     * The template is a DTO on the model; the form works with an array.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['template'] = $this->record->template->toArray();

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('applyTemplate')
                ->label(__('Apply to products'))
                ->icon('heroicon-o-document-duplicate')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription(__('Copies the template to every product of the collection, only where a product has no value yet.'))
                ->action(function (): void {
                    $count = CollectionResource::applyTemplateToProducts($this->record);
                    Notification::make()->title(__('Template applied to :count products', ['count' => $count]))->success()->send();
                }),
            Actions\Action::make('deleteCollection')
                ->label(__('Delete'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->modalHeading(fn (): string => __('Delete collection :name', ['name' => $this->record->name]))
                ->modalDescription(fn (): string => CollectionResource::deleteWarning($this->record))
                ->form([
                    Forms\Components\TextInput::make('confirm_name')
                        ->label(__('Type the collection name to confirm'))
                        ->required()
                        ->rule(fn () => function (string $attribute, mixed $value, \Closure $fail): void {
                            if (trim((string) $value) !== $this->record->name) {
                                $fail(__('The name does not match.'));
                            }
                        }),
                    Forms\Components\Toggle::make('delete_products')
                        ->label(__('Delete the products too'))
                        ->helperText(__('Products that are also in another collection are only detached.')),
                ])
                ->action(function (array $data): void {
                    (new DeleteCollectionAction)->execute($this->record, (bool) ($data['delete_products'] ?? false));

                    Notification::make()->title(__('Collection deleted'))->success()->send();

                    $this->redirect(CollectionResource::getUrl('index'));
                }),
        ];
    }
}
