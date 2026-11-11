<?php

namespace App\Events;

use App\Models\Incident;
use Illuminate\Foundation\Events\Dispatchable;

/** A visible incident was created, or a hidden one was made visible. */
class IncidentPublished
{
    use Dispatchable;

    public function __construct(public Incident $incident) {}
}
