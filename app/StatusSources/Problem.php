<?php

namespace App\StatusSources;

use App\Enums\ComponentStatus;
use Carbon\CarbonImmutable;

final class Problem
{
    public function __construct(
        public readonly string $eventid,
        public readonly ?string $triggerid,
        public readonly string $name,
        public readonly int $severity,
        public readonly CarbonImmutable $startedAt,
        public readonly ComponentStatus $status,
    ) {}
}
