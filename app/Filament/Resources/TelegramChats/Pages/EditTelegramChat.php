<?php

namespace App\Filament\Resources\TelegramChats\Pages;

use App\Filament\Resources\TelegramChats\TelegramChatResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditTelegramChat extends EditRecord
{
    protected static string $resource = TelegramChatResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
