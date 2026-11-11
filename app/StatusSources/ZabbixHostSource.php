<?php

namespace App\StatusSources;

use App\Enums\ComponentStatus;
use App\Zabbix\ZabbixClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Status from Zabbix triggers on hosts and/or host groups.
 *
 * Config:
 *   hosts:        list of host names (technical "host" or visible "name")
 *   host_groups:  list of host group names
 *   tags:         extra [{tag, value}] a trigger must carry, on top of the
 *                 global public tags (value "" = tag present with any value)
 *   severity_map: optional {0..5 => status|"ignore"} override
 *
 * A trigger counts for the component when it is on one of the hosts OR in one
 * of the host groups, and carries all the tags.
 */
class ZabbixHostSource implements StatusSource
{
    public function __construct(private readonly ZabbixClient $client) {}

    public static function label(): string
    {
        return 'Zabbix hosts / host groups';
    }

    public function resolve(Collection $sources): array
    {
        if ($sources->isEmpty()) {
            return [];
        }

        $triggers = $this->client->problemTriggers(
            self::parseTags(config('statuspage.zabbix.public_tags')),
            (bool) config('statuspage.zabbix.exclude_maintenance', true),
        );

        $results = [];

        foreach ($sources as $source) {
            $config = $this->normaliseConfig($source->config ?? []);
            $results[$source->id] = $this->resultFor($config, $triggers);
        }

        return $results;
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<array<string, mixed>>  $triggers
     */
    public function resultFor(array $config, array $triggers): SourceResult
    {
        $hosts = array_map('mb_strtolower', $config['hosts']);
        $groups = array_map('mb_strtolower', $config['host_groups']);
        $map = $config['severity_map'];
        $problems = [];

        foreach ($triggers as $trigger) {
            if (! $this->matchesTarget($trigger, $hosts, $groups) || ! $this->hasTags($trigger, $config['tags'])) {
                continue;
            }

            $severity = (int) ($trigger['priority'] ?? 0);
            $mapped = $map[$severity] ?? 'ignore';

            if ($mapped === 'ignore') {
                continue;
            }

            $event = $trigger['lastEvent'] ?? [];
            $clock = (int) ($event['clock'] ?? $trigger['lastchange'] ?? time());

            $problems[] = new Problem(
                eventid: (string) ($event['eventid'] ?? 'trigger-'.$trigger['triggerid']),
                triggerid: (string) $trigger['triggerid'],
                name: (string) (($event['name'] ?? '') ?: $trigger['description']),
                severity: $severity,
                startedAt: CarbonImmutable::createFromTimestamp($clock),
                status: ComponentStatus::from($mapped),
            );
        }

        return new SourceResult(
            ComponentStatus::worst(array_map(fn (Problem $p) => $p->status, $problems)),
            $problems,
        );
    }

    public function normaliseConfig(array $config): array
    {
        $list = fn ($value) => collect(is_array($value) ? $value : explode(',', (string) $value))
            ->map(fn ($v) => trim((string) $v))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $tags = collect($config['tags'] ?? [])
            ->filter(fn ($t) => is_array($t) && filled($t['tag'] ?? null))
            ->map(fn ($t) => ['tag' => trim($t['tag']), 'value' => trim((string) ($t['value'] ?? ''))])
            ->values()
            ->all();

        $default = config('statuspage.severity_map');
        $map = [];

        foreach (range(0, 5) as $severity) {
            $value = $config['severity_map'][$severity] ?? $config['severity_map'][(string) $severity] ?? $default[$severity];
            $map[$severity] = ($value === 'ignore' || ComponentStatus::tryFrom((string) $value)) ? $value : $default[$severity];
        }

        return [
            'hosts' => $list($config['hosts'] ?? []),
            'host_groups' => $list($config['host_groups'] ?? []),
            'tags' => $tags,
            'severity_map' => $map,
        ];
    }

    /**
     * "output=statuspage,team" => [[tag=>output,value=>statuspage],[tag=>team,value=>""]]
     *
     * @return list<array{tag: string, value: string}>
     */
    public static function parseTags(?string $spec): array
    {
        return collect(explode(',', (string) $spec))
            ->map(fn ($pair) => trim($pair))
            ->filter()
            ->map(function ($pair) {
                [$tag, $value] = array_pad(explode('=', $pair, 2), 2, '');

                return ['tag' => trim($tag), 'value' => trim($value)];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<string>  $hosts
     * @param  list<string>  $groups
     */
    private function matchesTarget(array $trigger, array $hosts, array $groups): bool
    {
        foreach ($trigger['hosts'] ?? [] as $host) {
            if (in_array(mb_strtolower($host['host'] ?? ''), $hosts, true) || in_array(mb_strtolower($host['name'] ?? ''), $hosts, true)) {
                return true;
            }
        }

        foreach ($trigger['groups'] ?? [] as $group) {
            if (in_array(mb_strtolower($group['name'] ?? ''), $groups, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $trigger
     * @param  list<array{tag: string, value: string}>  $required
     */
    private function hasTags(array $trigger, array $required): bool
    {
        foreach ($required as $want) {
            $found = collect($trigger['tags'] ?? [])->contains(fn ($t) => ($t['tag'] ?? null) === $want['tag']
                && ($want['value'] === '' || ($t['value'] ?? null) === $want['value']));

            if (! $found) {
                return false;
            }
        }

        return true;
    }
}
