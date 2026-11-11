<?php

namespace App\Filament\Resources\TelegramChats\Pages;

use App\Filament\Resources\TelegramChats\TelegramChatResource;
use App\Telegram\TelegramClient;
use Filament\Resources\Pages\CreateRecord;

class CreateTelegramChat extends CreateRecord
{
    protected static string $resource = TelegramChatResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return $data + ['added_by_admin' => true];
    }

    protected function afterCreate(): void
    {
        // Confirms the bot can post there, and fills in the chat type and title.
        if (TelegramClient::enabled()) {
            TelegramChatResource::sendTest($this->getRecord());
        }
    }
}
