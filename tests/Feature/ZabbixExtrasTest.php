<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\ComponentMetric;
use App\Models\Incident;
use App\Models\Setting;
use App\Services\IncidentManager;
use App\Services\MetricPoller;
use App\Services\StatusPoller;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class ZabbixExtrasTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    public function test_service_source_maps_service_status(): void
    {
        $this->fakeZabbix();
        $shop = Component::create(['name' => 'Shop']);
        $shop->sources()->create(['driver' => 'zabbix_service', 'config' => ['services' => ['Web shop']]]);
        $pay = Component::create(['name' => 'Payments']);
        $pay->sources()->create(['driver' => 'zabbix_service', 'config' => ['services' => ['Payments']]]);

        app(StatusPoller::class)->poll();

        $this->assertSame(ComponentStatus::MajorOutage, $shop->fresh()->status);
        $this->assertSame('Checkout failing', $shop->activeProblems()->value('name'));
        $this->assertSame(ComponentStatus::Operational, $pay->fresh()->status);

        // The problem's first sighting is kept across polls.
        $first = $shop->activeProblems()->value('started_at');
        $this->travel(5)->minutes();
        app(StatusPoller::class)->poll();
        $this->assertEquals($first, $shop->activeProblems()->value('started_at'));

        $this->fakeZabbix(ok: true);
        app(StatusPoller::class)->poll();
        $this->assertSame(ComponentStatus::Operational, $shop->fresh()->status);
    }

    public function test_service_problem_whose_trigger_was_deleted_is_ignored(): void
    {
        $this->fakeZabbix();
        [$legacy, $incident] = $this->stuckLegacyComponent(visible: true);

        app(StatusPoller::class)->poll();

        // Zabbix still says "Legacy site" is down, but only because of a
        // problem left behind by a deleted host: the component recovers.
        $legacy->refresh();
        $this->assertSame(ComponentStatus::Operational, $legacy->auto_status);
        $this->assertSame(ComponentStatus::Operational, $legacy->status);
        $this->assertSame(0, $legacy->activeProblems()->count());

        // A published incident is flagged for staff, not resolved.
        $incident->refresh();
        $this->assertFalse($incident->isResolved());
        $this->assertNotNull($incident->recovered_at);

        // A real problem on the same kind of service still counts.
        $shop = Component::create(['name' => 'Shop']);
        $shop->sources()->create(['driver' => 'zabbix_service', 'config' => ['services' => ['Web shop']]]);
        app(StatusPoller::class)->poll();
        $this->assertSame(ComponentStatus::MajorOutage, $shop->fresh()->status);
    }

    public function test_hidden_draft_for_orphaned_problem_is_resolved(): void
    {
        $this->fakeZabbix();
        Setting::put('auto_resolve_recovered_drafts', true);
        [, $incident] = $this->stuckLegacyComponent(visible: false);

        app(StatusPoller::class)->poll();

        $this->assertTrue($incident->fresh()->isResolved());
    }

    public function test_a_failing_driver_does_not_block_recoveries_elsewhere(): void
    {
        $this->fakeZabbix();
        [, $incident] = $this->stuckLegacyComponent(visible: true);
        $other = Component::create(['name' => 'Other']);
        $other->sources()->create(['driver' => 'no_such_driver', 'config' => []]);

        $result = app(StatusPoller::class)->poll();

        $this->assertFalse($result['ok']);
        $this->assertNotNull($incident->fresh()->recovered_at);
    }

    /**
     * The state left behind when a host feeding a service was deleted: an
     * active local problem and an open auto-created incident.
     *
     * @return array{Component, Incident}
     */
    private function stuckLegacyComponent(bool $visible): array
    {
        $legacy = Component::create(['name' => 'Legacy']);
        $legacy->sources()->create(['driver' => 'zabbix_service', 'config' => ['services' => ['Legacy site']]]);
        $legacy->problems()->create(['eventid' => '7002', 'name' => 'Unavailable by ICMP ping', 'severity' => 4, 'started_at' => now()->subDay()]);
        $legacy->forceFill(['auto_status' => ComponentStatus::MajorOutage])->saveQuietly();
        $legacy->refreshStatus();

        $incident = app(IncidentManager::class)->create([
            'title' => 'Legacy is unavailable',
            'status' => IncidentStatus::Identified,
            'impact' => IncidentImpact::Major,
            'visible' => $visible,
            'auto_created' => true,
            'zabbix_eventid' => '7002',
            'started_at' => now()->subDay(),
        ], [$legacy->id], 'Monitoring detected a problem.');

        $this->assertSame(ComponentStatus::MajorOutage, $legacy->fresh()->status);

        return [$legacy, $incident];
    }

    public function test_metric_poller_stores_bucketed_history(): void
    {
        $this->fakeZabbix('7.0.5');
        $c = Component::create(['name' => 'Portal']);
        $metric = ComponentMetric::create(['component_id' => $c->id, 'name' => 'Response time', 'host' => 'Website', 'item_key' => 'web.test.time[Portal]']);

        app(MetricPoller::class)->pollOne($metric);
        app(MetricPoller::class)->pollOne($metric->fresh()); // idempotent

        $metric->refresh();
        $this->assertSame('900', $metric->itemid);
        $this->assertSame('s', $metric->unit);
        $this->assertNull($metric->last_error);
        $this->assertGreaterThanOrEqual(6, $metric->points()->count());
        $this->assertLessThanOrEqual(7, $metric->points()->count());
    }

    public function test_metric_poller_reports_missing_and_non_numeric_items(): void
    {
        $this->fakeZabbix('7.0.5');
        $c = Component::create(['name' => 'Portal']);
        $missing = ComponentMetric::create(['component_id' => $c->id, 'name' => 'A', 'host' => 'Website', 'item_key' => 'nope']);
        $text = ComponentMetric::create(['component_id' => $c->id, 'name' => 'B', 'host' => 'Website', 'item_key' => 'text.item']);

        $result = app(MetricPoller::class)->poll();

        $this->assertSame(['ok' => 0, 'failed' => 2], $result);
        $this->assertStringContainsString('not found', $missing->fresh()->last_error);
        $this->assertStringContainsString('not numeric', $text->fresh()->last_error);
    }

    public function test_changing_the_item_forgets_old_samples(): void
    {
        $c = Component::create(['name' => 'Portal']);
        $metric = ComponentMetric::create(['component_id' => $c->id, 'name' => 'A', 'host' => 'h', 'item_key' => 'k']);
        $metric->forceFill(['itemid' => '1', 'value_type' => 0])->save();
        $metric->points()->create(['recorded_at' => now(), 'value' => 1]);

        $metric->update(['item_key' => 'other']);

        $this->assertNull($metric->fresh()->itemid);
        $this->assertSame(0, $metric->points()->count());
    }
}
