<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\Incident;
use App\Services\IncidentManager;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditIncident extends EditRecord
{
    protected static string $resource = IncidentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('view')->label('View public page')->color('gray')
                ->url(fn (Incident $record) => route('incidents.show', $record), shouldOpenInNewTab: true)
                ->visible(fn (Incident $record) => $record->visible),
            // A closure without a return value: each() stops early if it gets false.
            DeleteAction::make()->after(fn (Incident $record) => $record->components->each(function ($c): void {
                $c->refreshStatus();
            })),
        ];
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        $data['component_ids'] = $this->getRecord()->components()->pluck('components.id')->all();

        return $data;
    }

    /** @param Incident $record */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $wasVisible = $record->visible;
        $previous = $record->components()->pluck('components.id')->all();
        $componentIds = array_map('intval', $data['component_ids'] ?? []);
        unset($data['component_ids'], $data['message'], $data['status']);

        $record->update($data);
        $record->components()->sync($componentIds);

        app(IncidentManager::class)->edited($record, $wasVisible, $previous);

        return $record;
    }
}
