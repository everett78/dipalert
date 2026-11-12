<?php

namespace App\Filament\Resources;

use App\Actions\ApplyCollectionTemplateAction;
use App\Actions\DeleteCollectionAction;
use App\Dto\CollectionTemplateDto;
use App\Filament\Resources\CollectionResource\Pages;
use App\Filament\Resources\CollectionResource\RelationManagers\ProductsRelationManager;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use App\Services\Helpers\CurrencyHelper;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class CollectionResource extends Resource
{
    protected static ?string $model = ProductCollection::class;

    protected static ?int $navigationSort = 5;

    protected static ?string $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?string $slug = 'collections';

    /**
     * Heroicons offered for a collection.
     *
     * @var array<string, string>
     */
    public const array ICONS = [
        'heroicon-o-computer-desktop' => 'Komputer',
        'heroicon-o-device-phone-mobile' => 'Telefon',
        'heroicon-o-tv' => 'Telewizor',
        'heroicon-o-cpu-chip' => 'Podzespoły',
        'heroicon-o-printer' => 'Drukarka',
        'heroicon-o-camera' => 'Aparat',
        'heroicon-o-musical-note' => 'Audio',
        'heroicon-o-home' => 'Dom',
        'heroicon-o-home-modern' => 'Meble',
        'heroicon-o-light-bulb' => 'Oświetlenie',
        'heroicon-o-wrench-screwdriver' => 'Narzędzia',
        'heroicon-o-truck' => 'Motoryzacja',
        'heroicon-o-gift' => 'Prezenty',
        'heroicon-o-shopping-bag' => 'Zakupy',
        'heroicon-o-heart' => 'Zdrowie',
        'heroicon-o-sparkles' => 'Uroda',
        'heroicon-o-book-open' => 'Książki',
        'heroicon-o-puzzle-piece' => 'Zabawki',
        'heroicon-o-trophy' => 'Sport',
        'heroicon-o-cake' => 'Kuchnia',
        'heroicon-o-sun' => 'Ogród',
        'heroicon-o-tag' => 'Inne',
    ];

    public static function getNavigationLabel(): string
    {
        return __('Collections');
    }

    public static function getModelLabel(): string
    {
        return __('collection');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Collections');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('collections.user_id', auth()->id());
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make(__('Collection'))
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label(__('Name'))
                            ->required()
                            ->maxLength(255),
                        Forms\Components\Select::make('parent_id')
                            ->label(__('Parent collection'))
                            ->placeholder(__('None (top level)'))
                            ->options(fn (?ProductCollection $record): array => ProductCollection::query()
                                ->currentUser()
                                ->topLevel()
                                ->when($record?->exists, fn (Builder $query) => $query->whereKeyNot($record->getKey()))
                                ->orderBy('name')
                                ->pluck('name', 'id')
                                ->all())
                            // Two levels at most: a collection with subcollections stays at the top.
                            ->disabled(fn (?ProductCollection $record): bool => (bool) $record?->exists && $record->children()->exists())
                            ->helperText(fn (?ProductCollection $record): ?string => $record?->exists && $record->children()->exists()
                                ? __('A collection with subcollections stays at the top level.')
                                : null),
                        Forms\Components\Textarea::make('description')
                            ->label(__('Description'))
                            ->rows(2)
                            ->columnSpanFull(),
                        Forms\Components\Select::make('icon')
                            ->label(__('Icon'))
                            ->options(collect(self::ICONS)->mapWithKeys(fn (string $label, string $icon): array => [
                                $icon => '<span class="inline-flex items-center gap-2">'.svg($icon, 'h-4 w-4')->toHtml().e($label).'</span>',
                            ])->all())
                            ->allowHtml()
                            ->searchable(false)
                            ->native(false),
                        Forms\Components\FileUpload::make('image_path')
                            ->label(__('Image'))
                            ->disk('public')
                            ->directory('collections')
                            ->image()
                            ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp'])
                            ->maxSize(2048),
                        Forms\Components\Toggle::make('enabled')
                            ->label(__('Enabled'))
                            ->helperText(__('Products only in disabled collections are not checked.'))
                            ->default(true),
                        Forms\Components\TextInput::make('weight')
                            ->label(__('Sort order'))
                            ->numeric()
                            ->default(0),
                    ]),

                Forms\Components\Section::make(__('Template for products'))
                    ->description(__('Copied to products added to the collection, only where the product has no value yet.'))
                    ->statePath('template')
                    ->columns(3)
                    ->schema([
                        Forms\Components\TextInput::make('notify_change_percent')
                            ->label(__('Change from the previous read (%)'))
                            ->numeric()
                            ->minValue(0.01)
                            ->suffix('%'),
                        Forms\Components\TextInput::make('notify_change_amount')
                            ->label(__('Change from the previous read (amount)'))
                            ->numeric()
                            ->minValue(0.01)
                            ->suffix(CurrencyHelper::getSymbol()),
                        Forms\Components\Select::make('notify_change_direction')
                            ->label(__('Direction of the change'))
                            ->options([
                                Product::CHANGE_DOWN => __('Drop'),
                                Product::CHANGE_UP => __('Rise'),
                                Product::CHANGE_BOTH => __('Both'),
                            ])
                            ->native(false),
                        Forms\Components\Repeater::make('attributes')
                            ->label(__('Attribute fields'))
                            ->columnSpanFull()
                            ->columns(4)
                            ->defaultItems(0)
                            ->reorderable()
                            ->schema([
                                Forms\Components\TextInput::make('key')
                                    ->label(__('Key'))
                                    ->required()
                                    ->alphaDash(),
                                Forms\Components\TextInput::make('label')
                                    ->label(__('Label')),
                                Forms\Components\Select::make('type')
                                    ->label(__('Type'))
                                    ->options([
                                        CollectionTemplateDto::TYPE_TEXT => __('Text'),
                                        CollectionTemplateDto::TYPE_NUMBER => __('Number'),
                                    ])
                                    ->default(CollectionTemplateDto::TYPE_TEXT)
                                    ->selectablePlaceholder(false),
                                Forms\Components\TextInput::make('unit')
                                    ->label(__('Unit')),
                            ]),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('Name'))
                    ->weight(fn (ProductCollection $record): FontWeight => $record->parent_id === null ? FontWeight::Bold : FontWeight::Medium)
                    ->formatStateUsing(fn (string $state, ProductCollection $record): HtmlString => new HtmlString(
                        ($record->parent_id !== null ? '<span class="text-gray-400 pl-4">└ </span>' : '')
                        .($record->icon ? svg($record->icon, 'h-4 w-4 inline-block mr-1 text-gray-500')->toHtml() : '')
                        .e($state)
                    ))
                    ->searchable(),
                Tables\Columns\TextColumn::make('products_count')
                    ->label(__('Products'))
                    ->color('gray'),
                Tables\Columns\IconColumn::make('enabled')
                    ->label(__('Enabled'))
                    ->boolean(),
            ])
            // A tree: each top-level collection followed by its subcollections.
            ->modifyQueryUsing(fn (Builder $query) => $query
                ->withCount('products')
                ->orderByRaw('COALESCE(collections.parent_id, collections.id)')
                ->orderByRaw('collections.parent_id IS NOT NULL')
                ->orderBy('collections.weight')
                ->orderBy('collections.name'))
            ->paginated(false)
            ->actions([
                Tables\Actions\Action::make('applyTemplate')
                    ->label(__('Apply to products'))
                    ->icon('heroicon-o-document-duplicate')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalDescription(__('Copies the template to every product of the collection, only where a product has no value yet.'))
                    ->action(function (ProductCollection $record): void {
                        $count = self::applyTemplateToProducts($record);
                        Notification::make()->title(__('Template applied to :count products', ['count' => $count]))->success()->send();
                    }),
                Tables\Actions\EditAction::make(),
                self::deleteAction(),
            ]);
    }

    public static function applyTemplateToProducts(ProductCollection $collection): int
    {
        $action = new ApplyCollectionTemplateAction;
        $products = $collection->products()->get();

        $products->each(fn (Product $product) => $action->execute($collection, $product));

        return $products->count();
    }

    /**
     * Delete after typing the collection name; lists subcollections and the
     * number of products, optionally deleting the products too.
     */
    public static function deleteAction(): Tables\Actions\Action
    {
        return Tables\Actions\Action::make('deleteCollection')
            ->label(__('Delete'))
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->modalHeading(fn (ProductCollection $record): string => __('Delete collection :name', ['name' => $record->name]))
            ->modalDescription(fn (ProductCollection $record): string => self::deleteWarning($record))
            ->form([
                Forms\Components\TextInput::make('confirm_name')
                    ->label(__('Type the collection name to confirm'))
                    ->required()
                    ->rule(fn (ProductCollection $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        if (trim((string) $value) !== $record->name) {
                            $fail(__('The name does not match.'));
                        }
                    }),
                Forms\Components\Toggle::make('delete_products')
                    ->label(__('Delete the products too'))
                    ->helperText(__('Products that are also in another collection are only detached.')),
            ])
            ->action(function (ProductCollection $record, array $data): void {
                $result = (new DeleteCollectionAction)->execute($record, (bool) ($data['delete_products'] ?? false));

                Notification::make()
                    ->title(__('Deleted collections: :collections, deleted products: :deleted, detached products: :detached', [
                        'collections' => $result['collections'],
                        'deleted' => $result['deleted_products'],
                        'detached' => $result['detached_products'],
                    ]))
                    ->success()
                    ->send();
            });
    }

    public static function deleteWarning(ProductCollection $record): string
    {
        $ids = $record->children()->pluck('id')->push($record->getKey());
        $products = Product::query()->whereHas('collections', fn (Builder $query) => $query->whereIn('collections.id', $ids))->count();
        $children = $record->children()->pluck('name')->implode(', ');

        return trim(
            ($children !== '' ? __('Subcollections deleted with it: :names.', ['names' => $children]).' ' : '')
            .__('Products in these collections: :count.', ['count' => $products])
        );
    }

    public static function getRelations(): array
    {
        return [
            ProductsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCollections::route('/'),
            'create' => Pages\CreateCollection::route('/create'),
            'edit' => Pages\EditCollection::route('/{record}/edit'),
        ];
    }
}
