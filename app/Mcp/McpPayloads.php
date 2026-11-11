<?php

namespace App\Mcp;

use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\ZabbixProblem;
use App\Support\Payloads;

/**
 * The public API payloads plus the fields staff need: draft/hidden state,
 * what monitoring reports and any manual override.
 */
class McpPayloads
{
    /** @return array<string, mixed> */
    public static function component(Component $c): array
    {
        return Payloads::component($c) + [
            'enabled' => $c->enabled,
            'monitored_status' => $c->auto_status?->value,
            'manual_override' => $c->hasActiveManualOverride() ? [
                'status' => $c->manual_status->value,
                'until' => $c->manual_status_until?->toIso8601String(),
            ] : null,
        ];
    }

    /** @return array<string, mixed> */
    public static function problem(ZabbixProblem $p): array
    {
        return [
            'name' => $p->name,
            'severity' => $p->severityLabel(),
            'started_at' => $p->started_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function incident(Incident $i, bool $withUpdates = true): array
    {
        return Payloads::incident($i, $withUpdates) + [
            'visible' => $i->visible,
            'component_status' => $i->component_status?->value,
            'auto_created' => $i->auto_created,
        ];
    }

    /** @return array<string, mixed> */
    public static function maintenance(Maintenance $m): array
    {
        return Payloads::maintenance($m) + ['visible' => $m->visible];
    }
}
