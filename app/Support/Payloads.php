<?php

namespace App\Support;

use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;

/** Plain-array representations shared by the API and webhooks. */
class Payloads
{
    /** @return array<string, mixed> */
    public static function component(Component $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'description' => $c->description,
            'link' => $c->link,
            'group' => $c->group?->name,
            'status' => $c->status->value,
            'status_label' => $c->status->getLabel(),
            'status_changed_at' => $c->status_changed_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function incident(Incident $i, bool $withUpdates = true): array
    {
        $data = [
            'id' => $i->id,
            'title' => $i->title,
            'status' => $i->status->value,
            'impact' => $i->impact->value,
            'started_at' => $i->started_at->toIso8601String(),
            'resolved_at' => $i->resolved_at?->toIso8601String(),
            'url' => route('incidents.show', $i),
            'components' => $i->components->map(fn (Component $c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ];

        if ($withUpdates) {
            $data['updates'] = $i->updates->map(fn (IncidentUpdate $u) => self::update($u))->values();
        }

        return $data;
    }

    /** @return array<string, mixed> */
    public static function update(IncidentUpdate $u): array
    {
        return [
            'id' => $u->id,
            'status' => $u->status->value,
            'message' => $u->message,
            'created_at' => $u->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    public static function maintenance(Maintenance $m): array
    {
        return [
            'id' => $m->id,
            'title' => $m->title,
            'message' => $m->message,
            'status' => $m->status->value,
            'scheduled_start' => $m->scheduled_start->toIso8601String(),
            'scheduled_end' => $m->scheduled_end->toIso8601String(),
            'components' => $m->components->map(fn (Component $c) => ['id' => $c->id, 'name' => $c->name])->values(),
        ];
    }
}
