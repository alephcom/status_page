<?php

namespace App\Events;

use App\Models\IncidentUpdate;
use Illuminate\Foundation\Events\Dispatchable;

/** An update was added to a visible incident (not its first update). */
class IncidentUpdatePosted
{
    use Dispatchable;

    public function __construct(public IncidentUpdate $update) {}
}
