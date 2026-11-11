<?php

namespace App\Filament\Resources\Components\Pages;

use App\Filament\Resources\Components\ComponentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateComponent extends CreateRecord
{
    protected static string $resource = ComponentResource::class;

    /** Dependencies are saved after the record itself. */
    protected function afterCreate(): void
    {
        $this->getRecord()->refreshStatus();
    }
}
