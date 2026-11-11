<?php

use App\StatusSources\ZabbixHostSource;

return [

    /*
    | Zabbix API connection. Works with Zabbix 6.0 and later: the client asks
    | the API for its version and adapts authentication and parameter names.
    */
    'zabbix' => [
        'url' => env('ZABBIX_API_URL'),
        'token' => env('ZABBIX_API_TOKEN'),
        'timeout' => (int) env('ZABBIX_TIMEOUT', 10),
        'verify_ssl' => (bool) env('ZABBIX_VERIFY_SSL', true),

        // Only triggers carrying ALL of these tags are ever considered. This is
        // the safety boundary that keeps internal triggers off the public page.
        // Format: "tag=value,othertag=value" (value may be empty to match any).
        'public_tags' => env('ZABBIX_PUBLIC_TAGS', 'output=statuspage'),

        // Ignore triggers on hosts that are in a Zabbix maintenance period.
        'exclude_maintenance' => (bool) env('ZABBIX_EXCLUDE_MAINTENANCE', true),
    ],

    // Seconds without a successful poll before the public page says the data
    // may be out of date.
    'stale_after' => (int) env('STATUSPAGE_STALE_AFTER', 180),

    // Default mapping from Zabbix trigger severity to component status. Each
    // source can override it. "ignore" means the trigger has no effect.
    'severity_map' => [
        0 => 'ignore',          // Not classified
        1 => 'ignore',          // Information
        2 => 'degraded',        // Warning
        3 => 'partial_outage',  // Average
        4 => 'major_outage',    // High
        5 => 'major_outage',    // Disaster
    ],

    // Status source drivers, keyed by the name stored on component_sources.
    'sources' => [
        'zabbix_host' => ZabbixHostSource::class,
    ],

    'uptime_days' => (int) env('STATUSPAGE_UPTIME_DAYS', 90),
];
