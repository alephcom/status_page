<?php

namespace App\Events;

use App\Enums\ComponentStatus;
use App\Models\Component;
use Illuminate\Foundation\Events\Dispatchable;

class ComponentStatusChanged
{
    use Dispatchable;

    public function __construct(
        public Component $component,
        public ?ComponentStatus $from,
        public ComponentStatus $to,
    ) {}
}
