<?php

namespace App\Filament\Resources\IncidentTemplates\Pages;

use App\Filament\Resources\IncidentTemplates\IncidentTemplateResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditIncidentTemplate extends EditRecord
{
    protected static string $resource = IncidentTemplateResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
