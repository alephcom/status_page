<?php

namespace App\Filament\Resources\Users;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Filament\Concerns\RequiresPermission;
use App\Models\User;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

class UserResource extends Resource
{
    use RequiresPermission;

    protected static function requiredPermission(): Permission
    {
        return Permission::ManageUsers;
    }

    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            Select::make('role')->options(UserRole::class)->placeholder('User (no admin access)')
                ->default(UserRole::Editor)
                // Admins cannot change their own role, so nobody locks themselves out.
                ->disabled(fn (?User $record) => $record?->is(auth()->user()))
                // Stored as a Spatie role, not a column.
                ->formatStateUsing(fn (?User $record, $state) => $record ? $record->role : $state)
                ->dehydrated(false)
                ->saveRelationshipsUsing(fn (User $record, $state) => $record->setRole($state instanceof UserRole ? $state : UserRole::tryFrom((string) $state)))
                ->helperText('Users sign in to manage their own subscription. Editors also manage incidents and maintenance; admins manage everything.'),
            TextInput::make('password')->password()->revealable()
                ->required(fn (string $operation) => $operation === 'create')
                ->dehydrated(fn (?string $state) => filled($state))
                ->minLength(10),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('email')->searchable(),
                TextColumn::make('role')->badge()->placeholder('User'),
                TextColumn::make('email_verified_at')->label('Verified')->dateTime()->placeholder('Not verified')->toggleable(),
                TextColumn::make('created_at')->dateTime(),
            ])
            ->filters([
                SelectFilter::make('role')
                    ->options(['user' => 'User', ...collect(UserRole::cases())->mapWithKeys(fn (UserRole $r) => [$r->value => $r->getLabel()])])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        null, '' => $query,
                        'user' => $query->whereDoesntHave('roles'),
                        default => $query->role($data['value']),
                    }),
            ])
            ->recordActions([
                Action::make('makeAdmin')
                    ->label('Make admin')
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(fn (User $record) => "{$record->email} will be able to manage everything, including users and settings.")
                    ->visible(fn (User $record) => ! $record->isAdmin())
                    ->action(function (User $record) {
                        $record->setRole(UserRole::Admin);
                        Notification::make()->title("{$record->email} is now an admin")->success()->send();
                    }),
                EditAction::make(),
                DeleteAction::make()->hidden(fn (User $record) => $record->is(auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
