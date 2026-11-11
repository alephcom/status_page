<?php

namespace App\StatusSources;

use App\Enums\ComponentStatus;

final class SourceResult
{
    /**
     * @param  list<Problem>  $problems
     */
    public function __construct(
        public readonly ComponentStatus $status,
        public readonly array $problems = [],
    ) {}
}
