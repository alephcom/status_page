<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\CancelMaintenance;
use App\Mcp\Tools\CreateIncident;
use App\Mcp\Tools\GetComponent;
use App\Mcp\Tools\GetIncident;
use App\Mcp\Tools\GetStatus;
use App\Mcp\Tools\ListComponents;
use App\Mcp\Tools\ListIncidents;
use App\Mcp\Tools\ListIncidentTemplates;
use App\Mcp\Tools\ListMaintenances;
use App\Mcp\Tools\PostIncidentUpdate;
use App\Mcp\Tools\ScheduleMaintenance;
use App\Mcp\Tools\SetComponentOverride;
use App\Mcp\Tools\UpdateIncident;
use App\Mcp\Tools\UpdateMaintenance;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Status Page')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
    Read and manage the public status page: components, incidents and planned maintenance.

    - Component status is normally derived from Zabbix. Prefer opening an incident over a manual
      override; overrides hide what monitoring reports until they are cleared or expire.
    - Visible incidents, incident updates and maintenance windows e-mail subscribers and fire
      webhooks. Create an incident with visible=false to draft it, then publish with update-incident.
    - Resolve an incident by posting an update with status "resolved". Give post-incident-update a
      publish_at time to schedule an update instead of posting it now.
    - For recurring problems, list-incident-templates and pass template_id to create-incident.
    - Look up component ids with list-components before referencing them.
    TXT)]
class StatusPageServer extends Server
{
    protected array $tools = [
        // Read
        GetStatus::class,
        ListComponents::class,
        GetComponent::class,
        ListIncidents::class,
        GetIncident::class,
        ListMaintenances::class,
        ListIncidentTemplates::class,
        // Write
        CreateIncident::class,
        PostIncidentUpdate::class,
        UpdateIncident::class,
        ScheduleMaintenance::class,
        UpdateMaintenance::class,
        CancelMaintenance::class,
        SetComponentOverride::class,
    ];
}
