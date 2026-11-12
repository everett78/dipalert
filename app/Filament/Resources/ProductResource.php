<?php

namespace App\Filament\Resources;

use App\Actions\ApplyCollectionTemplateAction;
use App\Enums\Icons;
use App\Enums\Statuses;
use App\Filament\Resources\ProductResource\Actions\FetchBulkAction;
use App\Filament\Resources\ProductResource\Actions\PauseBulkAction;
use App\Filament\Resources\ProductResource\Actions\ResumeBulkAction;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use App\Filament\Resources\ProductResource\Columns\ProductCardColumn;
use App\Filament\Resources\ProductResource\Pages;
use App\Models\Collection as ProductCollection;
use App\Models\Product;
use App\Models\Tag;
use App\Providers\Filament\AdminPanelProvider;
use App\Rules\StoreUrl;
use App\Services\Helpers\CurrencyHelper;
use Filament\Forms;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\View\ComponentAttributeBag;

class ProductResource extends Resource
{
    protected static ?string $model = Product::class;

    protected static ?int $navigationSort = -1;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $recordTitleAttribute = 'title';

    protected static ?string $groupRouteName = 'products';

    public const string API_GROUP = 'Products';

    /**
     * Per-product check frequency options (seconds => label).
     */
    public const REFRESH_INTERVALS = [
        300 => 'Every 5 minutes',
        600 => 'Every 10 minutes',
        900 => 'Every 15 minutes',
        1800 => 'Every 30 minutes',
        3600 => 'Every hour',
        7200 => 'Every 2 hours',
        14400 => 'Every 4 hours',
        21600 => 'Every 6 hours',
        43200 => 'Every 12 hours',
        86400 => 'Every 24 hours',
    ];

    public static function getApiTransformer()
    {
        return ProductTransformer::class;
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema(fn ($livewire) => $livewire instanceof Pages\CreateProduct
                ? self::createForm($form)
                : self::editForm($form)
            );
    }

    public static function createForm(?Form $form = null, ?int $productId = null): array
    {
        $components = [];

        $components[] = TextInput::make('url')
            ->label('Product URL')
            ->hintIcon(Icons::Help->value, 'The domain of the URL must be in the list of available stores')
            ->rules([new StoreUrl])
            ->columnSpanFull();

        if (is_null($productId)) {
            $components[] = Select::make('product_id')
                ->label('Existing product')
                ->searchable(['title'])
                ->getSearchResultsUsing(fn (string $search): array => auth()->user()->products()->where('title', 'like', "%{$search}%")
                    ->limit(50)->pluck('title', 'id')
                    ->toArray()
                )
                ->hintIcon(Icons::Help->value, 'Add this URL to an existing product, leave empty to create a new product')
                ->nullable();
        }

        $components[] = self::createTagsSelect();

        $components[] = self::collectionsSelect();

        $components[] = TextInput::make('price_factor')
            ->label(__('Price Factor'))
            ->numeric()
            ->default(1)
            ->minValue(0.01)
            ->helperText(__('Number of items (unit price = price / factor)'));

        $components[] = Forms\Components\Toggle::make('create_store')
            ->label('Create store if it doesn\'t exist')
            ->hintIcon(Icons::Help->value, 'Attempt to create automatically create a store. Does not always work')
            ->default(true);

        return [
            Forms\Components\Section::make(__('Url of the product'))->schema($components)
                ->columns(2)
                ->description(__('Given the url we will scrape the product information. Products and their urls are unique to your user account')),
        ];
    }

    /**
     * Attach the chosen collections (only the user's own) and copy the template
     * of each newly added one into the product.
     *
     * @param  array<int, int|string>  $ids
     */
    public static function syncCollections(Product $product, array $ids, bool $detach = true): void
    {
        $chosen = ProductCollection::query()
            ->where('user_id', $product->user_id)
            ->whereIn('id', array_map('intval', $ids))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $result = $detach
            ? $product->collections()->sync($chosen)
            : $product->collections()->syncWithoutDetaching($chosen);

        $action = new ApplyCollectionTemplateAction;

        ProductCollection::query()->whereIn('id', $result['attached'])->get()
            ->each(fn (ProductCollection $collection) => $action->execute($collection, $product->refresh()));
    }

    /**
     * Delete a product after typing its name (requirement of the brief).
     */
    public static function deleteWithNameAction(): \Filament\Actions\Action
    {
        return \Filament\Actions\Action::make('deleteProduct')
            ->label(__('Delete'))
            ->icon(Icons::Delete->value)
            ->color('danger')
            ->modalHeading(__('Delete product'))
            ->modalDescription(__('The product and its price history will be deleted.'))
            ->form([
                TextInput::make('confirm_name')
                    ->label(__('Type the product name to confirm'))
                    ->required()
                    ->rule(fn (Product $record) => function (string $attribute, mixed $value, \Closure $fail) use ($record): void {
                        if (trim((string) $value) !== trim((string) $record->title)) {
                            $fail(__('The name does not match.'));
                        }
                    }),
            ])
            ->action(function (Product $record, \Filament\Actions\Action $action): void {
                $record->delete();

                \Filament\Notifications\Notification::make()->title(__('Product deleted'))->success()->send();
                $action->redirect(self::getUrl('index'));
            });
    }

    /**
     * Collections of the product. Not a relationship field: the pages sync it
     * themselves so they know which collections are new (their template is
     * copied into the product).
     */
    public static function collectionsSelect(): Select
    {
        return Select::make('collection_ids')
            ->label(__('Collections'))
            ->multiple()
            ->options(fn (): array => ProductCollection::query()
                ->currentUser()
                ->with('parent')
                ->get()
                ->sortBy(fn (ProductCollection $collection): string => $collection->fullName())
                ->mapWithKeys(fn (ProductCollection $collection): array => [$collection->getKey() => $collection->fullName()])
                ->all())
            ->searchable()
            ->preload()
            ->native(false)
            ->dehydrated(true);
    }

    /**
     * Attribute fields from the templates of the product's collections.
     */
    public static function attributeFieldsHint(?Product $product): ?string
    {
        if ($product === null) {
            return null;
        }

        $fields = $product->collections
            ->flatMap(fn (ProductCollection $collection): array => $collection->template->attributes)
            ->unique('key')
            ->map(fn (array $field): string => $field['label'].' ('.$field['key'].($field['unit'] ? ', '.$field['unit'] : '').')')
            ->implode(', ');

        return $fields === '' ? null : __('Fields from collections: :fields', ['fields' => $fields]);
    }

    /**
     * Tags multi-select for the create form. Unlike the edit form it does not use
     * `->relationship()` because CreateProduct::handleRecordCreation() persists tags
     * manually (merging onto existing products); the field just collects tag IDs.
     */
    protected static function createTagsSelect(): Select
    {
        return Select::make('tags')
            ->label('Tags')
            ->multiple()
            ->options(fn (): array => Tag::where('user_id', auth()->id())
                ->orderBy('name')
                ->pluck('name', 'id')
                ->toArray()
            )
            ->searchable()
            ->preload()
            ->native(false)
            ->createOptionForm([
                TextInput::make('name')
                    ->label('Tag name')
                    ->required()
                    ->maxLength(255)
                    ->unique(
                        Tag::class,
                        'name',
                        modifyRuleUsing: fn ($rule) => $rule->where('user_id', auth()->id())
                    ),

                TextInput::make('weight')
                    ->label('Sort weight')
                    ->numeric()
                    ->default(0)
                    ->helperText(TagResource::getWeightHelperText()),

                Hidden::make('user_id')
                    ->default(auth()->id()),
            ])
            ->createOptionUsing(fn (array $data) => Tag::create($data)->id)
            ->placeholder('Search tags or create new...')
            ->noSearchResultsMessage('No tags found');
    }

    public static function editForm(Form $form): array
    {
        return [
            Forms\Components\Section::make('Basics')->schema([
                TextInput::make('title')
                    ->label('Product title')
                    ->hintIcon(Icons::Help->value, 'The name of the product'),

                TextInput::make('image')
                    ->label('Image Url')
                    ->hintIcon(Icons::Help->value, 'The Image URL of the product'),

                TextInput::make('unit_of_measure')
                    ->label('Sold as')
                    ->placeholder('e.g. tablets, bags, 100g')
                    ->maxLength(50)
                    ->hintIcon(Icons::Help->value, 'Displayed after the price factor, e.g. (2 tablets)'),

                Select::make('status')
                    ->options(Statuses::class)
                    ->default(Statuses::Published)
                    ->preload()
                    ->hintIcon(Icons::Help->value, 'Only published products get price history')
                    ->native(false),

                Select::make('tags')
                    ->label('Tags')
                    ->multiple()
                    ->relationship(
                        'tags',
                        'name',
                        modifyQueryUsing: fn (Builder $query) => $query->where('user_id', auth()->id())
                    )
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->createOptionForm([
                        TextInput::make('name')
                            ->label('Tag name')
                            ->required()
                            ->maxLength(255)
                            ->unique(
                                Tag::class,
                                'name',
                                ignoreRecord: true,
                                modifyRuleUsing: fn ($rule) => $rule->where('user_id', auth()->id())
                            ),

                        TextInput::make('weight')
                            ->label('Sort weight')
                            ->numeric()
                            ->default(0)
                            ->helperText(TagResource::getWeightHelperText()),

                        Hidden::make('user_id')
                            ->default(auth()->id()),
                    ])
                    ->createOptionUsing(fn (array $data) => Tag::create($data)->id)
                    ->getOptionLabelsUsing(fn (array $values): array => Tag::whereIn('id', $values)
                        ->where('user_id', auth()->id())
                        ->pluck('name', 'id')
                        ->toArray()
                    )
                    ->placeholder('Search tags or create new...')
                    ->noSearchResultsMessage('No tags found'),

                self::collectionsSelect(),

                Select::make('weight')
                    ->label('Homepage sort order')
                    ->hintIcon(Icons::Help->value, 'The lower the number the higher it will appear on the homepage')
                    ->default('0')
                    ->options(collect(range(-50, 50))->mapWithKeys(fn ($value) => [strval($value) => strval($value)])->all()),

                Forms\Components\Toggle::make('favourite')
                    ->label('Favourite')
                    ->hintIcon(Icons::Help->value, 'Mark this product as favourite')
                    ->default(true),
            ])
                ->columns(2)
                ->description('Product info'),

            Forms\Components\Section::make('Notifications')->schema([
                TextInput::make('notify_price')
                    ->nullable()
                    ->suffix(CurrencyHelper::getSymbol())
                    ->hintIcon(Icons::Help->value, 'Get notified when price is equal or less than this value')
                    ->numeric(),

                TextInput::make('notify_percent')
                    ->nullable()
                    ->hintIcon(Icons::Help->value, 'Get notified when price drops below specified percentage')
                    ->suffix('%')
                    ->numeric(),

                TextInput::make('notify_change_percent')
                    ->label(__('Change from the previous read (%)'))
                    ->nullable()
                    ->hintIcon(Icons::Help->value, __('Get notified when the price of a link changes by at least this percent between two reads'))
                    ->suffix('%')
                    ->numeric()
                    ->minValue(0.01)
                    ->maxValue(999.99),

                TextInput::make('notify_change_amount')
                    ->label(__('Change from the previous read (amount)'))
                    ->nullable()
                    ->hintIcon(Icons::Help->value, __('Get notified when the price of a link changes by at least this amount between two reads'))
                    ->suffix(CurrencyHelper::getSymbol())
                    ->numeric()
                    ->minValue(0.01),

                Select::make('notify_change_direction')
                    ->label(__('Direction of the change'))
                    ->options([
                        Product::CHANGE_DOWN => __('Drop'),
                        Product::CHANGE_UP => __('Rise'),
                        Product::CHANGE_BOTH => __('Both'),
                    ])
                    ->placeholder(__('Drop'))
                    ->native(false),

                Forms\Components\Toggle::make('notify_in_stock')
                    ->label('Notify when back in stock')
                    ->hintIcon(Icons::Help->value, 'Get notified when a tracked url for this product becomes available again after being out of stock')
                    ->columnSpanFull(),

                Forms\Components\Toggle::make('notify_on_change')
                    ->label('Notify on any price change')
                    ->hintIcon(Icons::Help->value, 'Get notified every time the price of a tracked url goes up or down, regardless of the price and percent above')
                    ->columnSpanFull(),

            ])
                ->columns(2)
                ->description('Notification settings'),

            Forms\Components\Section::make(__('Attributes'))->schema([
                Forms\Components\KeyValue::make('attributes')
                    ->label('')
                    ->keyLabel(__('Key'))
                    ->valueLabel(__('Value'))
                    ->addActionLabel(__('Add attribute'))
                    ->helperText(fn (?Product $record): ?string => self::attributeFieldsHint($record)),
            ])
                ->collapsible()
                ->description(__('Product attributes, e.g. power or size')),

            Forms\Components\Section::make('Schedule')->schema([
                Select::make('refresh_interval')
                    ->label('Check frequency')
                    ->placeholder('Use global schedule (default)')
                    ->options(self::REFRESH_INTERVALS)
                    ->native(false)
                    ->hintIcon(Icons::Help->value, 'How often to check this product. Leave empty to follow the global fetch schedule. Very short intervals may get you blocked by some stores.'),

                Forms\Components\Toggle::make('paused')
                    ->label('Pause checking')
                    ->hintIcon(Icons::Help->value, 'Temporarily stop checking this product. It is skipped by both the global schedule and any custom frequency.'),
            ])
                ->columns(2)
                ->description('How often this product is checked'),
        ];
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\Layout\Split::make([
                        Tables\Columns\ImageColumn::make('primary_image')
                            ->width(60)
                            ->height(60)
                            ->extraImgAttributes([
                                'class' => 'rounded-md p-1 bg-white mr-2',
                                'onerror' => "this.onerror=null;this.src='/images/placeholder.png';",
                            ])
                            ->label('Image')
                            ->url(fn ($record): string => $record->action_urls['view'])
                            ->grow(false),

                        Tables\Columns\Layout\Stack::make([
                            TextColumn::make('title')
                                ->searchable()
                                ->formatStateUsing(fn ($state): HtmlString => new HtmlString('<span title="'.$state.'">'.Str::limit($state, 50).'</span>'))
                                ->sortable()
                                ->weight(FontWeight::Bold)
                                ->extraAttributes(['class' => 'pr-4 min-w-40'])
                                ->url(fn (Product $record): string => $record->action_urls['view']),

                            Tables\Columns\ViewColumn::make('badges')
                                ->view('components.product-badges')
                                ->viewData(['attributes' => new ComponentAttributeBag(['class' => 'flex md:gap-3 flex-col md:flex-row'])]),

                            TextColumn::make('tags')
                                ->color(Color::Gray)
                                ->formatStateUsing(fn ($record): string => $record->tags->pluck('name')->join(', '))
                                ->label('Tags')
                                ->url(null)
                                ->grow(false)
                                ->extraAttributes(['class' => 'mt-2 text-xs']),
                        ]),
                    ])->extraAttributes(['class' => 'max-w-md mb-2']),

                    ProductCardColumn::make('product_card')
                        ->label('Detail'),
                ])->extraAttributes(['class' => 'w-full'])->from('sm'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(Statuses::class)
                    ->label('Status')
                    ->native(false),
                SelectFilter::make('lowest_in_period')
                    ->label('Current price is lowest in')
                    ->placeholder('All time')
                    ->options([
                        '7' => 'Last week',
                        '30' => 'Last month',
                        '90' => 'Last 90 days',
                        '365' => 'Last year',
                    ])
                    ->query(function (Builder $query, array $data): void {
                        if (! empty($data['value'])) {
                            $query->lowestPriceInDays($data['value']);
                        }
                    }),
                SelectFilter::make('tags')
                    ->relationship('tags', 'name')
                    ->label('Tags')
                    ->multiple()
                    ->native(false),
                SelectFilter::make('collections')
                    ->relationship('collections', 'name', fn (Builder $query) => $query->where('collections.user_id', auth()->id()))
                    ->label(__('Collections'))
                    ->multiple()
                    ->native(false),
                Tables\Filters\TernaryFilter::make('paused')
                    ->label('Checking')
                    ->placeholder('All')
                    ->trueLabel('Paused only')
                    ->falseLabel('Active only'),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    FetchBulkAction::make(),
                    PauseBulkAction::make(),
                    ResumeBulkAction::make(),
                    // Deleting products asks to type a word first (requirement of the brief).
                    Tables\Actions\DeleteBulkAction::make()
                        ->form([
                            TextInput::make('confirm')
                                ->label(__('Type "usuń" to delete the selected products'))
                                ->required()
                                ->in(['usuń', 'USUŃ', 'Usuń']),
                        ]),
                ]),
            ])
            ->modifyQueryUsing(function (Builder $query) {
                $query->currentUser()->with(['tags']);
            })
            ->recordUrl(null);
    }

    public static function getRelations(): array
    {
        return [
            //
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
            'view' => Pages\ViewProduct::route('/{record}'),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->where('user_id', auth()->id());
    }

    /**
     * Send global-search (header) result clicks to the product's view page rather than
     * Filament's default (which prefers the edit page).
     */
    public static function getGlobalSearchResultUrl(Model $record): ?string
    {
        return static::getUrl('view', ['record' => $record]);
    }

    protected static function getAggregateTableColumn(string $method): TextColumn
    {
        return TextColumn::make($method.'_price')
            ->label(ucfirst($method))
            ->color(Color::Gray)
            ->formatStateUsing(fn (Product $record): string => strtoupper($method).' '.CurrencyHelper::toString($record->getPriceCacheAggregate($method)));
    }
}
