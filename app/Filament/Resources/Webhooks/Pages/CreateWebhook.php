<?php

namespace App\Filament\Resources\Webhooks\Pages;

use App\Filament\Resources\Webhooks\WebhookResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWebhook extends CreateRecord
{
    protected static string $resource = WebhookResource::class;
}
