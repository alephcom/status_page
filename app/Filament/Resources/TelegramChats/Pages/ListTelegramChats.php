<?php

namespace App\Filament\Resources\TelegramChats\Pages;

use App\Filament\Resources\TelegramChats\TelegramChatResource;
use App\Telegram\TelegramClient;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTelegramChats extends ListRecords
{
    protected static string $resource = TelegramChatResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Add channel or group')];
    }

    public function getSubheading(): ?string
    {
        return TelegramClient::enabled()
            ? 'Chats that sent /start to @'.TelegramClient::botUsername().', plus channels and groups added here, get the same notifications as e-mail subscribers.'
            : 'Telegram is not configured. Set TELEGRAM_BOT_TOKEN and TELEGRAM_BOT_USERNAME, then run php artisan statuspage:telegram-setup.';
    }
}
