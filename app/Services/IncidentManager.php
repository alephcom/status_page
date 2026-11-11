<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Events\IncidentPublished;
use App\Events\IncidentUpdatePosted;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * All incident writes go through here so component statuses and
 * notifications stay consistent wherever the change comes from.
 */
class IncidentManager
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $componentIds
     */
    public function create(array $attributes, array $componentIds, string $message, ?User $user = null): Incident
    {
        $incident = DB::transaction(function () use ($attributes, $componentIds, $message, $user) {
            $incident = Incident::create($attributes + ['started_at' => now()]);
            $incident->components()->sync($componentIds);
            $incident->updates()->create([
                'status' => $incident->status,
                'message' => $message,
                'user_id' => $user?->id,
            ]);

            return $incident;
        });

        $incident->refreshComponentStatuses();

        if ($incident->visible) {
            IncidentPublished::dispatch($incident);
        }

        return $incident;
    }

    /**
     * Call after editing an incident's own fields or components.
     *
     * @param  list<int>  $previousComponentIds
     */
    public function edited(Incident $incident, bool $wasVisible, array $previousComponentIds = []): void
    {
        $incident->refreshComponentStatuses();

        // Components removed from the incident may need to recover.
        if ($previousComponentIds !== []) {
            Component::query()->whereIn('id', $previousComponentIds)->get()->each(function (Component $c): void {
                $c->refreshStatus();
            });
        }

        if ($incident->visible && ! $wasVisible) {
            IncidentPublished::dispatch($incident);
        }
    }

    public function postUpdate(Incident $incident, IncidentStatus $status, string $message, ?User $user = null): IncidentUpdate
    {
        $update = DB::transaction(function () use ($incident, $status, $message, $user) {
            $update = $incident->updates()->create([
                'status' => $status,
                'message' => $message,
                'user_id' => $user?->id,
            ]);

            $incident->status = $status;
            $incident->save();

            return $update;
        });

        $incident->refreshComponentStatuses();

        if ($incident->visible) {
            IncidentUpdatePosted::dispatch($update);
        }

        return $update;
    }
}
