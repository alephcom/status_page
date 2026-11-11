<?php

namespace App\Events;

use App\Models\Maintenance;
use Illuminate\Foundation\Events\Dispatchable;

class MaintenanceScheduled
{
    use Dispatchable;

    public function __construct(public Maintenance $maintenance) {}
}
