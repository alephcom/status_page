<?php

namespace App\Filament\Resources\ComponentGroups\Pages;

use App\Filament\Resources\ComponentGroups\ComponentGroupResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListComponentGroups extends ListRecords
{
    protected static string $resource = ComponentGroupResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
