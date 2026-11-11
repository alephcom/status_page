<?php

namespace App\Zabbix;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Minimal JSON-RPC client for the Zabbix API, 6.0 and later.
 *
 * Version differences handled here:
 *  - 6.0/6.2 take the API token as "auth" in the request body; 6.4+ prefer the
 *    "Authorization: Bearer" header (and 7.2 removed the body field).
 *  - 6.0 names host group selectors "selectGroups"; 6.2+ use "selectHostGroups".
 */
class ZabbixClient
{
    private int $requestId = 0;

    public function __construct(
        private readonly ?string $url,
        private readonly ?string $token,
        private readonly int $timeout = 10,
        private readonly bool $verifySsl = true,
    ) {}

    public static function fromConfig(): self
    {
        $config = config('statuspage.zabbix');

        return new self($config['url'], $config['token'], (int) $config['timeout'], (bool) $config['verify_ssl']);
    }

    public function isConfigured(): bool
    {
        return filled($this->url);
    }

    /** API version, e.g. "6.0.25". Cached briefly; asked without authentication as Zabbix requires. */
    public function version(): string
    {
        return Cache::remember('zabbix.version.'.md5((string) $this->url), 3600, function () {
            return (string) $this->request('apiinfo.version', [], authenticated: false);
        });
    }

    public function atLeast(string $version): bool
    {
        return version_compare($this->version(), $version, '>=');
    }

    /** The selector name for host groups on this Zabbix version. */
    public function hostGroupSelector(): string
    {
        return $this->atLeast('6.2') ? 'selectHostGroups' : 'selectGroups';
    }

    /** Key under which host groups are returned for this version. */
    public function hostGroupKey(): string
    {
        return $this->atLeast('6.2') ? 'hostgroups' : 'groups';
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public function call(string $method, array $params = []): mixed
    {
        return $this->request($method, $params);
    }

    /**
     * Host group ids for the given exact names.
     *
     * @param  list<string>  $names
     * @return array<string, string> name => groupid
     */
    public function hostGroupIds(array $names): array
    {
        if ($names === []) {
            return [];
        }

        $groups = $this->call('hostgroup.get', [
            'output' => ['groupid', 'name'],
            'filter' => ['name' => array_values($names)],
        ]);

        return collect($groups)->pluck('groupid', 'name')->all();
    }

    /**
     * Triggers currently in the problem state that carry all the given tags.
     * Each trigger comes with its hosts, host groups (under "groups"), tags
     * and last event.
     *
     * @param  list<array{tag: string, value: string}>  $tags
     * @return list<array<string, mixed>>
     */
    public function problemTriggers(array $tags, bool $excludeMaintenance = true): array
    {
        $params = [
            'output' => ['triggerid', 'description', 'priority', 'value', 'lastchange'],
            'filter' => ['value' => 1],
            'monitored' => true,
            'skipDependent' => true,
            'expandDescription' => true,
            'selectHosts' => ['hostid', 'host', 'name'],
            $this->hostGroupSelector() => ['groupid', 'name'],
            'selectTags' => 'extend',
            'selectLastEvent' => ['eventid', 'clock', 'name', 'severity'],
        ];

        if ($tags !== []) {
            $params['tags'] = array_map(fn ($t) => ['tag' => $t['tag'], 'value' => $t['value'], 'operator' => $t['value'] === '' ? 4 : 1], $tags);
            $params['evaltype'] = 0;
        }

        if ($excludeMaintenance) {
            $params['maintenance'] = false;
        }

        $key = $this->hostGroupKey();

        return array_map(function (array $trigger) use ($key) {
            $trigger['groups'] = $trigger[$key] ?? [];

            return $trigger;
        }, $this->call('trigger.get', $params));
    }

    /**
     * IT services (Services / SLA) with the given exact names, with their
     * current problem events. Status -1 means OK; otherwise it is the
     * severity (0..5) of the worst problem affecting the service.
     *
     * @param  list<string>  $names
     * @return list<array<string, mixed>>
     */
    public function services(array $names): array
    {
        if ($names === []) {
            return [];
        }

        return $this->call('service.get', [
            'output' => ['serviceid', 'name', 'status'],
            'filter' => ['name' => array_values($names)],
            'selectProblemEvents' => ['eventid', 'severity', 'name'],
        ]);
    }

    /**
     * A numeric item on a host (technical or visible name), or null.
     *
     * @return array{itemid: string, value_type: int, units: string, name: string}|null
     */
    public function findItem(string $host, string $key): ?array
    {
        $hostIds = collect($this->call('host.get', ['output' => ['hostid'], 'filter' => ['host' => [$host]]]))
            ->concat($this->call('host.get', ['output' => ['hostid'], 'filter' => ['name' => [$host]]]))
            ->pluck('hostid')->unique()->values()->all();

        if ($hostIds === []) {
            return null;
        }

        $item = $this->call('item.get', [
            'output' => ['itemid', 'value_type', 'units', 'name'],
            'hostids' => $hostIds,
            'filter' => ['key_' => $key],
            'limit' => 1,
        ])[0] ?? null;

        return $item ? ['itemid' => (string) $item['itemid'], 'value_type' => (int) $item['value_type'], 'units' => (string) ($item['units'] ?? ''), 'name' => (string) ($item['name'] ?? '')] : null;
    }

    /**
     * History of one numeric item since $from (unix time), oldest first.
     *
     * @return list<array{clock: int, value: float}>
     */
    public function history(string $itemId, int $valueType, int $from, int $limit = 10000): array
    {
        $rows = $this->call('history.get', [
            'output' => ['clock', 'value'],
            'history' => $valueType,
            'itemids' => [$itemId],
            'time_from' => $from,
            'sortfield' => 'clock',
            'sortorder' => 'ASC',
            'limit' => $limit,
        ]);

        return array_map(fn (array $r) => ['clock' => (int) $r['clock'], 'value' => (float) $r['value']], $rows ?? []);
    }

    /**
     * Hosts matching a search string, for admin pickers.
     *
     * @return list<array{hostid: string, host: string, name: string}>
     */
    public function searchHosts(string $search, int $limit = 50): array
    {
        return $this->call('host.get', [
            'output' => ['hostid', 'host', 'name'],
            'search' => ['host' => $search, 'name' => $search],
            'searchByAny' => true,
            'limit' => $limit,
            'sortfield' => 'name',
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function request(string $method, array $params, bool $authenticated = true): mixed
    {
        if (! $this->isConfigured()) {
            throw new ZabbixException('Zabbix API URL is not configured (ZABBIX_API_URL).');
        }

        $body = [
            'jsonrpc' => '2.0',
            'method' => $method,
            'params' => (object) $params,
            'id' => ++$this->requestId,
        ];

        $http = $this->http();

        if ($authenticated && filled($this->token)) {
            if ($this->atLeast('6.4')) {
                $http = $http->withToken($this->token);
            } else {
                $body['auth'] = $this->token;
            }
        }

        try {
            $response = $http->post($this->url, $body);
        } catch (ConnectionException $e) {
            throw new ZabbixException("Cannot reach Zabbix API: {$e->getMessage()}", previous: $e);
        }

        if (! $response->successful()) {
            throw new ZabbixException("Zabbix API returned HTTP {$response->status()} for {$method}.");
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new ZabbixException("Zabbix API returned an invalid response for {$method}.");
        }

        if (isset($json['error'])) {
            $error = $json['error'];
            throw new ZabbixException(trim("Zabbix API error in {$method}: ".($error['message'] ?? '').' '.($error['data'] ?? '')));
        }

        return $json['result'] ?? null;
    }

    private function http(): PendingRequest
    {
        return Http::timeout($this->timeout)
            ->acceptJson()
            ->asJson()
            ->withOptions(['verify' => $this->verifySsl])
            ->retry(2, 500, throw: false);
    }
}
