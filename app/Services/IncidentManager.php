<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Events\IncidentPublished;
use App\Events\IncidentResolved;
use App\Events\IncidentUpdatePosted;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\ScheduledIncidentUpdate;
use App\Models\User;
use Carbon\CarbonInterface;
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
        $wasResolved = $incident->isResolved();

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

            if ($status->isResolved() && ! $wasResolved) {
                IncidentResolved::dispatch($incident);
            }
        }

        return $update;
    }

    /**
     * Queue an update to be posted at a later time.
     */
    public function scheduleUpdate(Incident $incident, IncidentStatus $status, string $message, CarbonInterface $publishAt, ?User $user = null): ScheduledIncidentUpdate
    {
        return $incident->scheduledUpdates()->create([
            'status' => $status,
            'message' => $message,
            'publish_at' => $publishAt,
            'user_id' => $user?->id,
        ]);
    }

    /**
     * Post every scheduled update that is due, oldest first.
     *
     * @return int number posted
     */
    public function publishDueUpdates(?CarbonInterface $now = null): int
    {
        $now ??= now();
        $posted = 0;

        ScheduledIncidentUpdate::query()->with(['incident', 'user'])
            ->where('publish_at', '<=', $now)->orderBy('publish_at')->orderBy('id')
            ->get()
            ->each(function (ScheduledIncidentUpdate $scheduled) use (&$posted): void {
                // Claim it first so two schedulers never post it twice.
                if (ScheduledIncidentUpdate::query()->whereKey($scheduled->id)->delete() === 0) {
                    return;
                }

                $this->postUpdate($scheduled->incident, $scheduled->status, $scheduled->message, $scheduled->user);
                $posted++;
            });

        return $posted;
    }

    /**
     * Note that monitoring recovered for an open incident. Hidden drafts that
     * were opened automatically are resolved straight away (nobody saw them);
     * anything else is flagged for staff to resolve.
     */
    public function monitoringRecovered(Incident $incident, bool $resolveDrafts): void
    {
        if ($incident->isResolved() || $incident->recovered_at !== null) {
            return;
        }

        if ($resolveDrafts && $incident->auto_created && ! $incident->visible) {
            $this->postUpdate($incident, IncidentStatus::Resolved, 'Monitoring reports that the problem has cleared.');

            return;
        }

        $incident->forceFill(['recovered_at' => now()])->saveQuietly();
    }
}
