<?php

namespace Tests\Fixtures;

/**
 * Canned Zabbix API data, shared by the tests and the dev mock server.
 *
 * Hosts match database/seeders/DemoSeeder.php. The data includes the awkward
 * cases: a trigger covering two hosts, a problem on a host no component uses,
 * an informational trigger that must be ignored, a trigger reached only via
 * its host group, and one lacking a per-source required tag.
 */
class ZabbixFixtures
{
    /**
     * Answer one JSON-RPC request the way the given Zabbix version would.
     *
     * @param  array<string, mixed>  $request  decoded JSON-RPC body
     * @return array<string, mixed> JSON-RPC response
     */
    public static function respond(array $request, string $version = '6.0.30', bool $ok = false, string $token = 'mock-token', ?string $bearer = null): array
    {
        $method = $request['method'] ?? '';
        $params = $request['params'] ?? [];
        $id = $request['id'] ?? 1;
        $newer = version_compare($version, '6.2', '>=');

        $error = fn (string $message, string $data = '') => ['jsonrpc' => '2.0', 'error' => ['code' => -32602, 'message' => $message, 'data' => $data], 'id' => $id];
        $result = fn ($r) => ['jsonrpc' => '2.0', 'result' => $r, 'id' => $id];

        if ($method === 'apiinfo.version') {
            return isset($request['auth']) || $bearer ? $error('Invalid params.', 'apiinfo.version does not take auth.') : $result($version);
        }

        $authOk = version_compare($version, '6.4', '>=')
            ? ($bearer === $token || ($request['auth'] ?? null) === $token)
            : ($request['auth'] ?? null) === $token;

        if (! $authOk) {
            return $error('Not authorised.', 'Session terminated, re-login, please.');
        }

        if (! $newer && isset($params['selectHostGroups'])) {
            return $error('Invalid params.', 'Invalid parameter "/": unexpected parameter "selectHostGroups".');
        }

        return match ($method) {
            'trigger.get' => $result(isset($params['triggerids'])
                ? self::triggersById((array) $params['triggerids'])
                : ($ok ? [] : self::triggers($newer ? 'hostgroups' : 'groups'))),
            'problem.get' => $result(array_values(array_filter(self::problems($ok), fn ($p) => in_array($p['eventid'], array_map('strval', (array) ($params['eventids'] ?? [])), true)))),
            'hostgroup.get' => $result(self::search(self::groups(), $params)),
            'host.get' => $result(self::search(self::hosts(), $params)),
            'service.get' => $result(array_values(array_filter(self::services($ok), fn ($svc) => in_array($svc['name'], (array) ($params['filter']['name'] ?? []), true)))),
            'item.get' => $result(($params['filter']['key_'] ?? null) === 'web.test.time[Portal]'
                ? [['itemid' => '900', 'value_type' => '0', 'units' => 's', 'name' => 'Response time']]
                : (($params['filter']['key_'] ?? null) === 'text.item' ? [['itemid' => '901', 'value_type' => '4', 'units' => '', 'name' => 'Text']] : [])),
            'history.get' => $result(self::history((int) ($params['time_from'] ?? 0))),
            default => $error('Method not found.', $method),
        };
    }

    /**
     * Apply the subset of filter/search semantics the app uses: exact
     * "filter" match, and case-insensitive substring "search" on any field.
     *
     * @param  list<array<string, string>>  $rows
     * @param  array<string, mixed>  $params
     * @return list<array<string, string>>
     */
    private static function search(array $rows, array $params): array
    {
        foreach ($params['filter'] ?? [] as $field => $values) {
            $rows = array_filter($rows, fn ($r) => in_array($r[$field] ?? null, (array) $values, true));
        }

        if (! empty($params['search'])) {
            $rows = array_filter($rows, function ($r) use ($params) {
                foreach ($params['search'] as $field => $needle) {
                    if (stripos($r[$field] ?? '', (string) $needle) !== false) {
                        return true;
                    }
                }

                return false;
            });
        }

        return array_slice(array_values($rows), 0, (int) ($params['limit'] ?? 1000));
    }

    /** @return list<array<string, string>> */
    public static function groups(): array
    {
        return [
            ['groupid' => '10', 'name' => 'Databases'],
            ['groupid' => '11', 'name' => 'Internal'],
        ];
    }

    /** @return list<array<string, string>> */
    public static function hosts(): array
    {
        return [
            ['hostid' => '1', 'host' => 'web01.example.net', 'name' => 'Website'],
            ['hostid' => '2', 'host' => 'mail01.example.net', 'name' => 'Mail'],
            ['hostid' => '3', 'host' => 'vpn-gw.example.net', 'name' => 'VPN gateway'],
            ['hostid' => '4', 'host' => 'db01.example.net', 'name' => 'db01'],
            ['hostid' => '5', 'host' => 'api01.example.net', 'name' => 'API'],
            ['hostid' => '9', 'host' => 'build01.internal', 'name' => 'Build server'],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function triggers(string $groupKey = 'hostgroups'): array
    {
        $now = time();
        $tag = [['tag' => 'output', 'value' => 'statuspage']];

        $t = fn (string $id, string $desc, int $prio, array $hosts, array $groups, array $tags, int $ago, string $eventid) => [
            'triggerid' => $id,
            'description' => $desc,
            'priority' => (string) $prio,
            'value' => '1',
            'lastchange' => (string) ($now - $ago),
            'hosts' => $hosts,
            $groupKey => $groups,
            'tags' => $tags,
            'lastEvent' => ['eventid' => $eventid, 'clock' => (string) ($now - $ago), 'name' => $desc, 'severity' => (string) $prio],
        ];

        $h = fn (string $hostid) => collect(self::hosts())->firstWhere('hostid', $hostid);

        return [
            // Website down: High -> major outage.
            $t('100', 'Website is not responding', 4, [$h('1')], [], $tag, 900, '5001'),
            // Shared trigger on mail and VPN: Average -> partial outage for both.
            $t('101', 'Upstream link packet loss', 3, [$h('2'), $h('3')], [], $tag, 1800, '5002'),
            // Information on mail: ignored by the default severity map.
            $t('102', 'Mail queue informational', 1, [$h('2')], [], $tag, 600, '5003'),
            // Only reachable via host group "Databases": Warning -> degraded.
            $t('103', 'Replication lag', 2, [$h('4')], [['groupid' => '10', 'name' => 'Databases']], $tag, 300, '5004'),
            // On a host no component uses: must not leak anywhere.
            $t('104', 'Build disk full', 5, [$h('9')], [['groupid' => '11', 'name' => 'Internal']], $tag, 120, '5005'),
            // On the API host but without the extra "service=api" tag the API source requires.
            $t('105', 'API CPU high', 4, [$h('5')], [], $tag, 60, '5006'),
        ];
    }

    /**
     * IT services: "Web shop" has a High problem (unless all is OK), "Payments"
     * is fine. "Legacy site" still reports a High problem whose trigger was
     * deleted along with its host: Zabbix keeps such orphans until the
     * housekeeper runs, and the service keeps counting them.
     *
     * @return list<array<string, mixed>>
     */
    public static function services(bool $ok = false): array
    {
        return [
            ['serviceid' => '1', 'name' => 'Web shop', 'status' => $ok ? '-1' : '4',
                'problem_events' => $ok ? [] : [['eventid' => '7001', 'severity' => '4', 'name' => 'Checkout failing']]],
            ['serviceid' => '2', 'name' => 'Payments', 'status' => '-1', 'problem_events' => []],
            ['serviceid' => '3', 'name' => 'Legacy site', 'status' => '4',
                'problem_events' => [['eventid' => '7002', 'severity' => '4', 'name' => 'Unavailable by ICMP ping']]],
        ];
    }

    /**
     * Open problems by event id. 7002 points at trigger 199, which no longer exists.
     *
     * @return list<array<string, string>>
     */
    public static function problems(bool $ok = false): array
    {
        return array_values(array_filter([
            $ok ? null : ['eventid' => '7001', 'objectid' => '106', 'r_eventid' => '0'],
            ['eventid' => '7002', 'objectid' => '199', 'r_eventid' => '0'],
        ]));
    }

    /**
     * trigger.get by id: the problem triggers plus the one behind "Web shop".
     * Trigger 199 (deleted with its host) is deliberately absent.
     *
     * @param  list<string|int>  $ids
     * @return list<array{triggerid: string}>
     */
    private static function triggersById(array $ids): array
    {
        $known = [...array_column(self::triggers(), 'triggerid'), '106'];
        $ids = array_map('strval', $ids);

        return array_values(array_map(
            fn ($id) => ['triggerid' => $id],
            array_filter($known, fn ($id) => in_array($id, $ids, true)),
        ));
    }

    /**
     * Response times every 10 minutes for the last hour.
     *
     * @return list<array{clock: string, value: string}>
     */
    public static function history(int $from): array
    {
        $now = time();
        $out = [];

        for ($t = $now - 3600; $t <= $now; $t += 600) {
            if ($t >= $from) {
                $out[] = ['clock' => (string) $t, 'value' => (string) (0.2 + ($t % 7) / 100)];
            }
        }

        return $out;
    }
}
