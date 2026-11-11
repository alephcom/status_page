<?php

namespace App\Filament\Resources\TelegramChats;

use App\Filament\Concerns\AdminOnly;
use App\Models\Setting;
use App\Models\TelegramChat;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramMessage;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class TelegramChatResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = TelegramChat::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChatBubbleLeftRight;

    protected static string|UnitEnum|null $navigationGroup = 'Notifications';

    protected static ?string $modelLabel = 'Telegram chat';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('chat_id')->label('Chat')->required()->unique(ignoreRecord: true)
                ->disabledOn('edit')
                ->helperText('A public channel as @name, or a numeric chat id. Add the bot to the channel or group as an admin first.'),
            TextInput::make('title')->maxLength(255)->helperText('For your reference.'),
            Select::make('components')->relationship('components', 'name', fn ($query) => $query->public())->multiple()->preload()
                ->helperText('Empty = all notifications. Private components never notify subscribers.')->columnSpanFull(),
            Select::make('min_impact')->label('Incidents to send')->options([
                'minor' => 'Minor and more severe',
                'major' => 'Major and critical only',
                'critical' => 'Critical only',
            ])->placeholder('All incidents')->helperText('Maintenance notices are always sent.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->placeholder('—')
                    ->description(fn (TelegramChat $record) => $record->chat_id),
                TextColumn::make('type')->badge()->color('gray')->placeholder('—'),
                TextColumn::make('added_by_admin')->label('Source')
                    ->formatStateUsing(fn (bool $state) => $state ? 'Admin' : '/start'),
                TextColumn::make('components.name')->badge()->color('gray')->limitList(3)->placeholder('All'),
                TextColumn::make('last_error')->color('danger')->placeholder('—')->wrap(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('added_by_admin')->label('Added by an admin'),
            ])
            ->recordActions([
                Action::make('test')->label('Send test')->icon(Heroicon::OutlinedPaperAirplane)
                    ->visible(fn () => TelegramClient::enabled())
                    ->action(fn (TelegramChat $record) => self::sendTest($record)),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function sendTest(TelegramChat $chat): void
    {
        $response = app(TelegramClient::class)->sendMessage($chat->chat_id,
            'Test message from <b>'.TelegramMessage::escape(Setting::get('site_name')).'</b>. Notifications will arrive here.');

        if ($response->successful()) {
            $chat->forceFill([
                'last_error' => null,
                'type' => $response->json('result.chat.type', $chat->type),
                'title' => $chat->title ?: $response->json('result.chat.title'),
            ])->save();
            Notification::make()->success()->title('Test message sent')->send();

            return;
        }

        $error = (string) $response->json('description', 'HTTP '.$response->status());
        $chat->forceFill(['last_error' => mb_substr($error, 0, 255)])->save();
        Notification::make()->danger()->title('Telegram refused the message')->body($error)->send();
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTelegramChats::route('/'),
            'create' => Pages\CreateTelegramChat::route('/create'),
            'edit' => Pages\EditTelegramChat::route('/{record}/edit'),
        ];
    }
}
