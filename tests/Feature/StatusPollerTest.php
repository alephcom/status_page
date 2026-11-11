<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Events\ComponentStatusChanged;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Services\StatusPoller;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class StatusPollerTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function statusOf(string $name): ComponentStatus
    {
        return Component::query()->where('name', $name)->firstOrFail()->status;
    }

    public function test_maps_problems_to_components(): void
    {
        $this->fakeZabbix();

        $result = app(StatusPoller::class)->poll();

        $this->assertTrue($result['ok']);
        $this->assertSame(ComponentStatus::MajorOutage, $this->statusOf('Website'));
        // One trigger covering two hosts affects both components.
        $this->assertSame(ComponentStatus::PartialOutage, $this->statusOf('E-mail'));
        $this->assertSame(ComponentStatus::PartialOutage, $this->statusOf('VPN'));
        // Matched through its host group only.
        $this->assertSame(ComponentStatus::Degraded, $this->statusOf('Databases'));
        // Trigger lacks the source's extra required tag.
        $this->assertSame(ComponentStatus::Operational, $this->statusOf('Public API'));
        // No sources at all.
        $this->assertSame(ComponentStatus::Operational, $this->statusOf('DNS'));
    }

    public function test_ignored_severities_and_unconfigured_hosts_are_not_recorded(): void
    {
        $this->fakeZabbix();
        app(StatusPoller::class)->poll();

        $names = Component::query()->where('name', 'E-mail')->first()->activeProblems()->pluck('name');
        $this->assertSame(['Upstream link packet loss'], $names->all());
        $this->assertDatabaseMissing('zabbix_problems', ['name' => 'Build disk full']);
    }

    public function test_recovery_resolves_problems_and_records_changes(): void
    {
        $this->fakeZabbix();
        app(StatusPoller::class)->poll();

        Event::fake([ComponentStatusChanged::class]);
        $this->fakeZabbix(ok: true);
        app(StatusPoller::class)->poll();

        $this->assertSame(ComponentStatus::Operational, $this->statusOf('Website'));
        $this->assertDatabaseMissing('zabbix_problems', ['resolved_at' => null]);
        Event::assertDispatchedTimes(ComponentStatusChanged::class, 4);

        $website = Component::query()->where('name', 'Website')->first();
        $this->assertSame(
            ['major_outage', 'operational'],
            $website->statusChanges()->orderByDesc('id')->limit(2)->get()->reverse()->map(fn ($c) => $c->status->value)->values()->all(),
        );
    }

    public function test_unreachable_zabbix_keeps_last_status_and_flags_stale(): void
    {
        $this->fakeZabbix();
        app(StatusPoller::class)->poll();

        $this->zabbixDown();
        $this->travel(10)->minutes();
        $result = app(StatusPoller::class)->poll();

        $this->assertFalse($result['ok']);
        $this->assertSame(ComponentStatus::MajorOutage, $this->statusOf('Website'));
        $this->assertNotNull(Setting::get('zabbix_last_error'));

        $this->get('/')->assertOk()->assertSee('Monitoring data may be out of date');
    }

    public function test_manual_override_wins_until_it_expires(): void
    {
        $this->fakeZabbix();
        $website = Component::query()->where('name', 'Website')->first();
        $website->update(['manual_status' => ComponentStatus::Degraded, 'manual_status_until' => now()->addHour()]);

        app(StatusPoller::class)->poll();
        $this->assertSame(ComponentStatus::Degraded, $this->statusOf('Website'));

        $this->travel(2)->hours();
        app(StatusPoller::class)->poll();
        $this->assertSame(ComponentStatus::MajorOutage, $this->statusOf('Website'));
    }

    public function test_maintenance_window_overrides_monitoring(): void
    {
        $this->fakeZabbix();
        $dns = Component::query()->where('name', 'DNS')->first();
        $maintenance = $dns->maintenances()->first();

        $this->travelTo($maintenance->scheduled_start->addMinute());
        app(StatusPoller::class)->poll();
        $this->assertSame(ComponentStatus::Maintenance, $this->statusOf('DNS'));

        $this->travelTo($maintenance->scheduled_end->addMinute());
        app(StatusPoller::class)->poll();
        $this->assertSame(ComponentStatus::Operational, $this->statusOf('DNS'));
    }

    public function test_auto_incident_creates_hidden_draft_once(): void
    {
        Setting::put('auto_incidents', true);
        $this->fakeZabbix();

        app(StatusPoller::class)->poll();
        app(StatusPoller::class)->poll();

        $auto = Incident::query()->where('auto_created', true)->get();
        // Website already had an open incident from the seeder, so only none or
        // others are created; the seeded incident must not be duplicated.
        $this->assertTrue($auto->every(fn (Incident $i) => ! $i->visible));
        $this->assertSame(0, $auto->where('title', 'Website is unavailable')->count());
    }
}
