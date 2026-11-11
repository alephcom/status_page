<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentMetric;
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
