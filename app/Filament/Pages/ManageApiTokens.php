<?php

namespace App\Filament\Pages;

use App\Models\User;
use BackedEnum;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Pages\Page;
use Filament\Schemas\Components\Text;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Laravel\Sanctum\PersonalAccessToken;
use UnitEnum;

/**
 * Bearer tokens for the MCP endpoint. Everyone manages their own tokens;
 * admins also see and revoke everyone else's and can issue tokens for
 * other staff (e.g. a dedicated account for an on-call agent).
 */
class ManageApiTokens extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.pages.manage-api-tokens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'API tokens';

    protected static ?string $slug = 'api-tokens';

    public static function canAccess(): bool
    {
        return auth()->user()?->role !== null;
    }

    private function isAdmin(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PersonalAccessToken::query()
                ->where('tokenable_type', (new User)->getMorphClass())
                ->when(! $this->isAdmin(), fn (Builder $q) => $q->where('tokenable_id', auth()->id()))
                ->with('tokenable'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('tokenable.email')->label('User')->visible($this->isAdmin()),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('last_used_at')->label('Last used')->since()->placeholder('Never')->sortable(),
                TextColumn::make('expires_at')->label('Expires')->dateTime()->placeholder('Never')->sortable()
                    ->color(fn (PersonalAccessToken $record) => $record->expires_at?->isPast() ? 'danger' : null),
            ])
            ->filters([
                SelectFilter::make('tokenable_id')->label('User')->visible($this->isAdmin())
                    ->options(fn () => User::query()->orderBy('email')->pluck('email', 'id')),
            ])
            ->headerActions([$this->createAction()])
            ->recordActions([
                DeleteAction::make()->label('Revoke')->modalHeading('Revoke token')
                    ->modalDescription('Clients using this token stop working immediately.')
                    ->successNotificationTitle('Token revoked'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->label('Revoke selected'),
                ]),
            ])
            ->emptyStateHeading('No API tokens')
            ->emptyStateDescription('Create a token to connect an AI assistant or agent to the MCP endpoint.');
    }

    private function createAction(): Action
    {
        return Action::make('create')
            ->label('Create token')
            ->icon(Heroicon::OutlinedPlus)
            ->schema([
                TextInput::make('name')->required()->maxLength(255)
                    ->placeholder('e.g. Claude, on-call agent')
                    ->helperText('So you can tell your tokens apart.'),
                Select::make('user_id')->label('For user')
                    ->visible($this->isAdmin())
                    ->options(fn () => User::query()->orderBy('email')->pluck('email', 'id'))
                    ->default(auth()->id())->required()
                    ->helperText('Actions taken with the token are recorded under this user, with their permissions.'),
                DateTimePicker::make('expires_at')->label('Expires')->minDate(now())
                    ->helperText('Leave empty for a token that lasts until revoked.'),
            ])
            ->action(function (array $data): void {
                $user = $this->isAdmin() ? User::query()->findOrFail($data['user_id']) : auth()->user();
                $token = $user->createToken($data['name'], expiresAt: filled($data['expires_at'] ?? null) ? Carbon::parse($data['expires_at']) : null);

                $this->replaceMountedAction('showToken', ['token' => $token->plainTextToken]);
            });
    }

    public function showTokenAction(): Action
    {
        return Action::make('showToken')
            ->modalHeading('Your new token')
            ->modalDescription('Copy it now. It will not be shown again.')
            ->schema(fn (array $arguments) => [
                TextInput::make('token')->label('Token')->default($arguments['token'] ?? '')
                    ->readOnly()->copyable(),
                Text::make('Endpoint: '.url('/mcp').'. Send it as "Authorization: Bearer <token>".'),
            ])
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Done');
    }
}
