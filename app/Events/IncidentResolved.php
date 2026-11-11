<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

/** A visible incident was resolved. Fired after IncidentUpdatePosted. */
class IncidentResolved
{
    use Dispatchable;

    public function __construct(public Incident $incident) {}
}
