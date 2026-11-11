<?php

namespace App\Ai;

use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\ZabbixProblem;
use Illuminate\Support\Collection;

/** Builds the information an IncidentWriter drafts from. */
class IncidentContext
{
    /**
     * @param  Collection<int, Component>  $components
     * @return array<string, mixed>
     */
    public static function forComponents(Collection $components, ?string $notes = null): array
    {
        $components->loadMissing('activeProblems');

        return array_filter([
            'components' => $components->map(fn (Component $c) => self::component($c))->values()->all(),
            'notes_from_staff' => $notes ?: null,
            'now' => now()->toIso8601String(),
        ]);
    }

    /** @return array<string, mixed> */
    public static function forIncident(Incident $incident): array
    {
        $incident->loadMissing(['components.activeProblems', 'updates']);

        return [
            'incident' => [
                'title' => $incident->title,
                'status' => $incident->status->value,
                'impact' => $incident->impact->value,
                'started_at' => $incident->started_at->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
            ],
            'timeline' => $incident->updates->sortBy('created_at')->map(fn (IncidentUpdate $u) => [
                'at' => $u->created_at->toIso8601String(),
                'status' => $u->status->value,
                'message' => $u->message,
            ])->values()->all(),
            'components' => $incident->components->map(fn (Component $c) => self::component($c, $incident))->values()->all(),
            'now' => now()->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private static function component(Component $c, ?Incident $incident = null): array
    {
        $problems = $incident
            ? $c->problems()->where('started_at', '<=', $incident->resolved_at ?? now())
                ->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $incident->started_at->copy()->subHour()))
                ->orderBy('started_at')->limit(20)->get()
            : $c->activeProblems;

        return [
            'name' => $c->name,
            'description' => $c->description,
            'current_status' => $c->status->value,
            'monitoring_problems' => $problems->map(fn (ZabbixProblem $p) => array_filter([
                'name' => $p->name,
                'severity' => $p->severityLabel(),
                'started_at' => $p->started_at?->toIso8601String(),
                'resolved_at' => $p->resolved_at?->toIso8601String(),
            ]))->values()->all(),
        ];
    }
}
