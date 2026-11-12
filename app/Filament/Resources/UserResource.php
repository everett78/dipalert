<?php

namespace App\Filament\Resources;

use App\Enums\NotificationMethods;
use App\Enums\Role;
use App\Filament\Resources\UserResource\Pages;
use App\Filament\Traits\FormHelperTrait;
use App\Models\User;
use App\Providers\Filament\AdminPanelProvider;
use Filament\Forms;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Support\Enums\FontWeight;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Hash;

class UserResource extends Resource
{
    use FormHelperTrait;

    protected static ?string $model = User::class;

    protected static ?string $navigationGroup = 'System';

    protected static ?int $navigationSort = 110;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    /**
     * Allow any authenticated user to reach the resource's routes so a non-admin
     * can edit their OWN record via the "Account settings" link. Per-page checks
     * govern actual access: the list page requires viewAny (admin), create requires
     * create (admin), and edit requires update (admin or self).
     */
    public static function canAccess(): bool
    {
        return (bool) auth()->user();
    }

    /**
     * Hide the Users nav item from non-admins (canAccess is intentionally broad above).
     */
    public static function shouldRegisterNavigation(): bool
    {
        return static::canViewAny();
    }

    /**
     * Restrict global search of users to admins (defense-in-depth, since
     * canAccess() is intentionally broad to permit self-edit).
     */
    public static function canGloballySearch(): bool
    {
        return static::canViewAny();
    }

    public static function form(Form $form): Form
    {
        return $form
            ->schema([
                Section::make('Account')
                    ->description('Manage account details.')
                    ->columns(2)
                    ->schema([
                        Forms\Components\TextInput::make('name')
                            ->label('Name')
                            ->required(),
                        Forms\Components\TextInput::make('email')
                            ->label('Email')
                            ->email()
                            ->unique(ignoreRecord: true)
                            ->required(),
                        Forms\Components\TextInput::make('password')
                            ->label('Password')
                            ->password()
                            ->dehydrateStateUsing(fn ($state) => Hash::make($state))
                            ->dehydrated(fn ($state) => filled($state))
                            ->required(fn (string $context): bool => $context === 'create'),
                        Select::make('role')
                            ->label('Role')
                            ->options(Role::class)
                            ->default(Role::User)
                            ->required()
                            ->visible(fn (): bool => auth()->user()?->isAdmin() ?? false),
                    ]),
                self::makeFormHeading('Notification Settings'),
                self::getEmailSettings(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\Layout\Split::make([
                    Tables\Columns\TextColumn::make('name')
                        ->weight(FontWeight::Bold)
                        ->searchable(),
                    Tables\Columns\TextColumn::make('email')
                        ->searchable(),
                ])->from('sm'),
            ])
            ->paginated(AdminPanelProvider::DEFAULT_PAGINATION)
            ->filters([
                //
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }

    protected static function getEmailSettings(): Section
    {
        return self::makeSettingsSection(
            'Email',
            'settings.notifications',
            NotificationMethods::Mail->value
        );
    }
}
