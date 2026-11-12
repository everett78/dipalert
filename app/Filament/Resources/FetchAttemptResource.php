<?php

namespace App\Filament\Resources;

use App\Enums\FetchOutcome;
use App\Filament\Resources\FetchAttemptResource\Pages;
use App\Models\FetchAttempt;
use App\Models\Store;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * "Problems": fetch attempts that did not end with "ok".
 */
class FetchAttemptResource extends Resource
{
    protected static ?string $model = FetchAttempt::class;

    protected static ?string $navigationIcon = 'heroicon-o-exclamation-triangle';

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 110;

    protected static ?string $slug = 'problems';

    public static function getNavigationLabel(): string
    {
        return __('Problems');
    }

    public static function getModelLabel(): string
    {
        return __('fetch problem');
    }

    public static function getPluralModelLabel(): string
    {
        return __('Problems');
    }

    protected static function isAdmin(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin();
    }

    public static function canViewAny(): bool
    {
        return self::isAdmin();
    }

    public static function canView(Model $record): bool
    {
        return self::isAdmin();
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('outcome', '!=', FetchOutcome::Ok->value)
            ->with(['url.product', 'store']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('url.product.title')
                    ->label(__('Product'))
                    ->limit(60)
                    ->searchable()
                    ->url(fn (FetchAttempt $record): ?string => $record->url?->product?->view_url),
                TextColumn::make('store.name')
                    ->label(__('Store'))
                    ->placeholder('—'),
                TextColumn::make('outcome')
                    ->label(__('Result'))
                    ->badge()
                    ->formatStateUsing(fn (FetchOutcome $state): string => $state->getLabel())
                    ->color(fn (FetchOutcome $state): string => $state->getColor()),
                TextColumn::make('http_status')
                    ->label(__('HTTP status'))
                    ->placeholder('—'),
                TextColumn::make('message')
                    ->label(__('Message'))
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('created_at')
                    ->label(__('Time'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->defaultSort('created_at', 'desc')
            ->filters([
                SelectFilter::make('outcome')
                    ->label(__('Result'))
                    ->options(collect(FetchOutcome::cases())
                        ->reject(fn (FetchOutcome $outcome): bool => $outcome === FetchOutcome::Ok)
                        ->mapWithKeys(fn (FetchOutcome $outcome): array => [$outcome->value => $outcome->getLabel()])
                        ->all()),
                SelectFilter::make('store_id')
                    ->label(__('Store'))
                    ->options(fn (): array => Store::query()->orderBy('name')->pluck('name', 'id')->all()),
                Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('From')),
                        DatePicker::make('until')->label(__('Until')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    /**
     * File name for "Download HTML".
     */
    public static function downloadName(FetchAttempt $record): string
    {
        return 'dipalert-'.$record->getKey().'-'.Str::slug($record->outcome->value).'.html';
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFetchAttempts::route('/'),
            'view' => Pages\ViewFetchAttempt::route('/{record}'),
        ];
    }
}
