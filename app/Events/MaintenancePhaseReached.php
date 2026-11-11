<?php

namespace App\Events;

use App\Models\Maintenance;
use Illuminate\Foundation\Events\Dispatchable;

/** A visible maintenance window is about to start, has started or has ended. */
class MaintenancePhaseReached
{
    use Dispatchable;

    public const REMINDER = 'reminder';

    public const STARTED = 'started';

    public const COMPLETED = 'completed';

    public function __construct(public Maintenance $maintenance, public string $phase) {}
}
