<?php

namespace App\Services;

use App\Events\MaintenanceScheduled;
use App\Models\Component;
use App\Models\Maintenance;

class MaintenanceManager
{
    /**
     * Call after a maintenance window was created or edited.
     *
     * @param  list<int>  $previousComponentIds
     */
    public function saved(Maintenance $maintenance, array $previousComponentIds = []): void
    {
        $ids = array_unique([...$previousComponentIds, ...$maintenance->components()->pluck('components.id')->all()]);
        Component::query()->whereIn('id', $ids)->get()->each->refreshStatus();

        if ($maintenance->visible && ! $maintenance->notified && $maintenance->scheduled_end->isFuture()) {
            $maintenance->forceFill(['notified' => true])->saveQuietly();
            MaintenanceScheduled::dispatch($maintenance);
        }
    }

    public function deleting(Maintenance $maintenance): void
    {
        $components = $maintenance->components()->get();
        $maintenance->components()->detach();
        $components->each->refreshStatus();
    }
}
