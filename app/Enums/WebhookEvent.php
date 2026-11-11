<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebhookEvent: string implements HasLabel
{
    case IncidentCreated = 'incident.created';
    case IncidentUpdated = 'incident.updated';
    case MaintenanceScheduled = 'maintenance.scheduled';
    case ComponentStatusChanged = 'component.status_changed';

    public function getLabel(): string
    {
        return $this->value;
    }
}
