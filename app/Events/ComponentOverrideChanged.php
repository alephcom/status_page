<?php

namespace App\Events;

use App\Models\Component;
use Illuminate\Foundation\Events\Dispatchable;

/** A manual status override was set, changed or cleared. */
class ComponentOverrideChanged
{
    use Dispatchable;

    public function __construct(public Component $component) {}
}
