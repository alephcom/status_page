<?php

namespace App\Filament\Resources\Webhooks;

use App\Enums\WebhookEvent;
use App\Filament\Concerns\AdminOnly;
use App\Jobs\SendWebhook;
use App\Models\Webhook;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

class WebhookResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Webhook::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = 'Notifications';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('url')->url()->required()->maxLength(255),
            TextInput::make('secret')->password()->revealable()
                ->helperText('Optional. Signs the body: X-Statuspage-Signature: sha256=HMAC.')
                ->dehydrated(fn (?string $state) => filled($state)),
            Toggle::make('enabled')->default(true),
            CheckboxList::make('events')->options(WebhookEvent::class)->required()->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('url')->limit(50),
                TextColumn::make('events')->badge()->color('gray'),
                IconColumn::make('enabled')->boolean(),
                TextColumn::make('last_status')->label('Last HTTP')->badge()
                    ->color(fn (?int $state) => $state && $state < 300 ? 'success' : 'danger'),
                TextColumn::make('last_sent_at')->since(),
            ])
            ->recordActions([
                Action::make('test')->icon(Heroicon::OutlinedPaperAirplane)->color('gray')
                    ->action(function (Webhook $record) {
                        try {
                            SendWebhook::dispatchSync($record, 'ping', ['message' => 'Test from the status page']);
                            Notification::make()->success()->title("Delivered (HTTP {$record->fresh()->last_status})")->send();
                        } catch (Throwable $e) {
                            Notification::make()->danger()->title('Delivery failed')->body($e->getMessage())->send();
                        }
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWebhooks::route('/'),
            'create' => Pages\CreateWebhook::route('/create'),
            'edit' => Pages\EditWebhook::route('/{record}/edit'),
        ];
    }
}
