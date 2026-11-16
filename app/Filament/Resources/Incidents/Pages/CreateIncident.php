<?php

namespace App\Filament\Resources\Incidents\Pages;

use App\Ai\IncidentWriter;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Services\IncidentManager;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateIncident extends CreateRecord
{
    protected static string $resource = IncidentResource::class;

    /**
     * Opened from "Draft incident" (?components=…&draft=1): start as a hidden
     * draft and let Claude write it from the monitoring problems.
     */
    protected function afterFill(): void
    {
        if (! request()->boolean('draft') || ! app(IncidentWriter::class)->enabled()) {
            return;
        }

        $componentIds = $this->data['component_ids'] ?? [];
        if ($componentIds === [] || ! $draft = IncidentResource::draftFor($componentIds)) {
            return;
        }

        $this->data['title'] = $draft['title'];
        $this->data['message'] = $draft['message'];
        $this->data['impact'] = $draft['impact']->value;
        $this->data['visible'] = false;
    }

    protected function handleRecordCreation(array $data): Model
    {
        $componentIds = array_map('intval', $data['component_ids'] ?? []);
        $message = $data['message'];
        unset($data['component_ids'], $data['message'], $data['template']);

        return app(IncidentManager::class)->create($data, $componentIds, $message, auth()->user());
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('edit', ['record' => $this->getRecord()]);
    }
}
