<?php

namespace Database\Seeders;

use App\Enums\ComponentVisibility;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\IncidentTemplate;
use App\Models\Maintenance;
use App\Models\MetricPoint;
use App\Models\Setting;
use App\Services\IncidentManager;
use App\Services\UptimeCalculator;
use Illuminate\Database\Seeder;

/**
 * Demo data matching tests/Fixtures/ZabbixFixtures.php, so the mock Zabbix
 * server produces a realistic page.
 */
class DemoSeeder extends Seeder
{
    public function run(IncidentManager $incidents): void
    {
        Setting::putMany([
            'site_name' => 'Example Co. status',
            'site_about' => 'Live status of our public services, fed from our Zabbix monitoring.',
            'external_links' => [
                ['name' => 'Cloud provider', 'url' => 'https://status.example.com', 'description' => 'Upstream hosting'],
            ],
        ]);

        $public = ComponentGroup::create(['name' => 'Public services', 'description' => 'Services our customers use.', 'order' => 1]);
        $backend = ComponentGroup::create(['name' => 'Platform', 'description' => 'Supporting infrastructure.', 'order' => 2, 'collapsed' => true]);

        $make = function (ComponentGroup $group, string $name, int $order, array $config, ?string $description = null) {
            $c = Component::create(['component_group_id' => $group->id, 'name' => $name, 'order' => $order, 'description' => $description]);
            $c->sources()->create(['driver' => 'zabbix_host', 'config' => $config + ['hosts' => [], 'host_groups' => [], 'tags' => []]]);

            return $c;
        };

        $website = $make($public, 'Website', 1, ['hosts' => ['web01.example.net']], 'www.example.net');
        $make($public, 'E-mail', 2, ['hosts' => ['Mail']]);
        $make($public, 'VPN', 3, ['hosts' => ['vpn-gw.example.net']]);
        $make($public, 'Public API', 4, ['hosts' => ['api01.example.net'], 'tags' => [['tag' => 'service', 'value' => 'api']]]);
        $make($backend, 'Databases', 1, ['host_groups' => ['Databases']]);
        $dns = Component::create(['component_group_id' => $backend->id, 'name' => 'DNS', 'order' => 2]);

        $incidents->create([
            'title' => 'Website unavailable',
            'status' => IncidentStatus::Identified,
            'impact' => IncidentImpact::Major,
            'started_at' => now()->subMinutes(15),
        ], [$website->id], 'The website is not responding. We have identified a failed load balancer and are replacing it.');

        $old = $incidents->create([
            'title' => 'Slow e-mail delivery',
            'status' => IncidentStatus::Investigating,
            'impact' => IncidentImpact::Minor,
            'started_at' => now()->subDays(2),
        ], [], 'Some messages are delayed by up to 30 minutes.');
        $incidents->postUpdate($old, IncidentStatus::Resolved, 'Delivery is back to normal.');

        $maintenance = Maintenance::create([
            'title' => 'DNS server upgrade',
            'message' => 'Short interruptions to name resolution are possible.',
            'scheduled_start' => now()->addDay()->setTime(22, 0),
            'scheduled_end' => now()->addDay()->setTime(23, 30),
        ]);
        $maintenance->components()->attach($dns);

        $website->update(['sla_target' => 99.9]);

        IncidentTemplate::create([
            'name' => 'Load balancer failover',
            'title' => 'Website unavailable',
            'message' => 'The website is not responding. We are failing over to the standby load balancer.',
            'impact' => 'major',
            'component_status' => 'major_outage',
            'component_ids' => [$website->id],
        ]);

        Component::create([
            'component_group_id' => $backend->id,
            'name' => 'Build farm',
            'description' => 'Only staff and internal networks see this one.',
            'order' => 3,
            'visibility' => ComponentVisibility::Private,
        ]);

        $this->backfillHistory();
        $this->sampleMetric($website);
    }

    /** A response-time graph with a day of made-up samples (the mock Zabbix serves the last hour). */
    private function sampleMetric(Component $website): void
    {
        $metric = $website->metrics()->create([
            'name' => 'Response time',
            'host' => 'Website',
            'item_key' => 'web.test.time[Portal]',
            'unit' => 's',
            'decimals' => 2,
        ]);

        $start = now()->subDay()->startOfHour();
        $rows = [];

        for ($t = $start->copy(), $i = 0; $t->lessThan(now()); $t->addMinutes(5), $i++) {
            $rows[] = [
                'component_metric_id' => $metric->id,
                'recorded_at' => $t->toDateTimeString(),
                'value' => round((180 + 40 * sin($i / 30) + mt_rand(0, 25) + ($i > 200 && $i < 212 ? 400 : 0)) / 1000, 3),
            ];
        }

        MetricPoint::query()->insert($rows);
    }

    /**
     * Pretend the components have existed for 60 days with a few past
     * outages, so the uptime bars have something to show.
     */
    private function backfillHistory(): void
    {
        mt_srand(42);
        $start = now()->subDays(60)->startOfDay();

        foreach (Component::all() as $component) {
            $component->forceFill(['created_at' => $start])->saveQuietly();
            $component->statusChanges()->oldest('id')->first()?->update(['changed_at' => $start]);

            for ($i = 0, $n = mt_rand(0, 4); $i < $n; $i++) {
                $at = $start->copy()->addMinutes(mt_rand(0, 59 * 24 * 60));
                $bad = ['degraded', 'partial_outage', 'major_outage'][mt_rand(0, 2)];
                $component->statusChanges()->createMany([
                    ['from_status' => 'operational', 'status' => $bad, 'changed_at' => $at],
                    ['from_status' => $bad, 'status' => 'operational', 'changed_at' => $at->copy()->addMinutes(mt_rand(10, 240))],
                ]);
            }

            app(UptimeCalculator::class)->rollup($component, $start, today());
        }
    }
}
