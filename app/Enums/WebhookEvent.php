<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebhookEvent: string implements HasLabel
{
    case IncidentCreated = 'incident.created';
    case IncidentUpdated = 'incident.updated';
    case IncidentResolved = 'incident.resolved';
    case MaintenanceScheduled = 'maintenance.scheduled';
    case MaintenanceStarted = 'maintenance.started';
    case MaintenanceCompleted = 'maintenance.completed';
    case ComponentStatusChanged = 'component.status_changed';
    case ComponentOverrideChanged = 'component.override_changed';

    public function getLabel(): string
    {
        return $this->value;
    }
}
