<?php

namespace App\Filament\Resources\Maintenances\Pages;

use App\Filament\Resources\Maintenances\MaintenanceResource;
use App\Models\Maintenance;
use App\Services\MaintenanceManager;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMaintenance extends EditRecord
{
    protected static string $resource = MaintenanceResource::class;

    /** @var list<int> */
    private array $previousComponentIds = [];

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()->before(fn (Maintenance $record) => app(MaintenanceManager::class)->deleting($record)),
        ];
    }

    protected function beforeSave(): void
    {
        $this->previousComponentIds = $this->getRecord()->components()->pluck('components.id')->all();
    }

    protected function afterSave(): void
    {
        app(MaintenanceManager::class)->saved($this->getRecord(), $this->previousComponentIds);
    }
}
