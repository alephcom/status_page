<?php

namespace Tests\Unit;

use App\Enums\ComponentStatus;
use PHPUnit\Framework\TestCase;

class ComponentStatusTest extends TestCase
{
    public function test_worst_picks_the_highest_rank_and_ignores_nulls(): void
    {
        $this->assertSame(ComponentStatus::Operational, ComponentStatus::worst([]));
        $this->assertSame(ComponentStatus::Degraded, ComponentStatus::worst([null, ComponentStatus::Degraded, ComponentStatus::Operational]));
        $this->assertSame(ComponentStatus::PartialOutage, ComponentStatus::worst([ComponentStatus::Maintenance, ComponentStatus::PartialOutage]));
    }

    public function test_maintenance_outranks_degraded_but_not_outages(): void
    {
        $this->assertSame(ComponentStatus::Maintenance, ComponentStatus::worst([ComponentStatus::Degraded, ComponentStatus::Maintenance]));
        $this->assertSame(ComponentStatus::MajorOutage, ComponentStatus::worst([ComponentStatus::Maintenance, ComponentStatus::MajorOutage]));
    }

    public function test_ordered_runs_best_to_worst(): void
    {
        $this->assertSame(
            [ComponentStatus::Operational, ComponentStatus::Degraded, ComponentStatus::Maintenance, ComponentStatus::PartialOutage, ComponentStatus::MajorOutage],
            ComponentStatus::ordered(),
        );
    }
}
