<?php

namespace Tests\Unit;

use App\Enums\ComponentStatus;
use App\Models\ComponentStatusDaily;
use PHPUnit\Framework\TestCase;

class ComponentStatusDailySegmentsTest extends TestCase
{
    public function test_short_outage_is_a_small_visible_sliver(): void
    {
        $row = new ComponentStatusDaily(['operational_seconds' => 86280, 'major_outage_seconds' => 120]);
        $segments = $row->segments(15);

        $this->assertSame(ComponentStatus::MajorOutage, $segments[0]['status']);
        $this->assertEqualsWithDelta(15, $segments[0]['share'], 0.001);
        $this->assertEqualsWithDelta(85, $segments[1]['share'], 0.001);
    }

    public function test_shares_always_fill_the_bar(): void
    {
        $row = new ComponentStatusDaily(['operational_seconds' => 80000, 'degraded_seconds' => 60, 'partial_outage_seconds' => 60, 'major_outage_seconds' => 6280]);
        $total = array_sum(array_column($row->segments(15), 'share'));

        $this->assertEqualsWithDelta(100, $total, 0.001);
    }

    public function test_no_data_has_no_segments(): void
    {
        $this->assertSame([], (new ComponentStatusDaily)->segments());
    }
}
