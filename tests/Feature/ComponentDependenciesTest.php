<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Component;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComponentDependenciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dependents_show_the_worst_status_of_what_they_depend_on(): void
    {
        $db = Component::create(['name' => 'Database']);
        $api = Component::create(['name' => 'API']);
        $app = Component::create(['name' => 'App']);
        $api->dependencies()->attach($db);
        $app->dependencies()->attach($api);

        $db->update(['manual_status' => ComponentStatus::MajorOutage]);

        $this->assertSame(ComponentStatus::MajorOutage, $api->fresh()->status);
        $this->assertSame(ComponentStatus::MajorOutage, $app->fresh()->status);

        $db->update(['manual_status' => null]);

        $this->assertSame(ComponentStatus::Operational, $api->fresh()->status);
        $this->assertSame(ComponentStatus::Operational, $app->fresh()->status);
    }

    public function test_disabled_dependencies_are_ignored(): void
    {
        $db = Component::create(['name' => 'Database', 'enabled' => false]);
        $api = Component::create(['name' => 'API']);
        $api->dependencies()->attach($db);

        $db->update(['manual_status' => ComponentStatus::MajorOutage]);

        $this->assertSame(ComponentStatus::Operational, $api->fresh()->status);
    }

    public function test_cycles_are_detected(): void
    {
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);
        $c = Component::create(['name' => 'C']);
        $b->dependencies()->attach($a);
        $c->dependencies()->attach($b);

        $this->assertTrue($a->wouldCycle($c->id));
        $this->assertTrue($a->wouldCycle($a->id));
        $this->assertFalse($c->wouldCycle($a->id));
    }
}
