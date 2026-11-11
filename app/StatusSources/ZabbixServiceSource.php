<?php

namespace App\StatusSources;

use App\Enums\ComponentStatus;
use App\Zabbix\ZabbixClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Status from Zabbix IT services (Services / SLA, Zabbix 6.0+).
 *
 * Config:
 *   services:     list of service names
 *   severity_map: optional {0..5 => status|"ignore"} override
 *
 * A service's status is the severity of the worst problem affecting it (or
 * OK); that severity is mapped to a component status. The services' own
 * problem tags and rules decide what counts, so build services from the
 * triggers you are happy to publish.
 */
class ZabbixServiceSource implements StatusSource
{
    public function __construct(private readonly ZabbixClient $client) {}

    public static function label(): string
    {
        return 'Zabbix services (SLA)';
    }

    public function resolve(Collection $sources): array
    {
        if ($sources->isEmpty()) {
            return [];
        }

        $configs = $sources->mapWithKeys(fn ($s) => [$s->id => $this->normaliseConfig($s->config ?? [])]);
        $names = $configs->flatMap(fn ($c) => $c['services'])->unique()->values()->all();
        $services = collect($this->client->services($names))->keyBy(fn ($s) => mb_strtolower($s['name']));

        return $configs->map(fn (array $config) => $this->resultFor($config, $services->all()))->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, array<string, mixed>>  $services  keyed by lower-case name
     */
    public function resultFor(array $config, array $services): SourceResult
    {
        $map = $config['severity_map'];
        $statuses = [];
        $problems = [];

        foreach ($config['services'] as $name) {
            $service = $services[mb_strtolower($name)] ?? null;

            if ($service === null || (int) $service['status'] < 0) {
                continue;
            }

            $mapped = $map[(int) $service['status']] ?? 'ignore';

            if ($mapped === 'ignore') {
                continue;
            }

            $statuses[] = ComponentStatus::from($mapped);

            foreach ($service['problem_events'] ?? [] as $event) {
                $severity = (int) ($event['severity'] ?? 0);
                $eventStatus = $map[$severity] ?? 'ignore';

                $problems[$event['eventid']] = new Problem(
                    eventid: (string) $event['eventid'],
                    triggerid: null,
                    name: (string) ($event['name'] ?? $service['name']),
                    severity: $severity,
                    startedAt: CarbonImmutable::now(),
                    status: $eventStatus === 'ignore' ? ComponentStatus::Operational : ComponentStatus::from($eventStatus),
                );
            }
        }

        return new SourceResult(ComponentStatus::worst($statuses), array_values($problems));
    }

    public function normaliseConfig(array $config): array
    {
        $services = collect(is_array($config['services'] ?? null) ? $config['services'] : explode(',', (string) ($config['services'] ?? '')))
            ->map(fn ($v) => trim((string) $v))->filter()->unique()->values()->all();

        $default = config('statuspage.severity_map');
        $map = [];

        foreach (range(0, 5) as $severity) {
            $value = $config['severity_map'][$severity] ?? $config['severity_map'][(string) $severity] ?? $default[$severity];
            $map[$severity] = ($value === 'ignore' || ComponentStatus::tryFrom((string) $value)) ? $value : $default[$severity];
        }

        return ['services' => $services, 'severity_map' => $map];
    }
}
