<?php

/**
 * Stand-in Zabbix API for local development:
 *
 *   php -S 127.0.0.1:3002 tests/MockZabbix/server.php
 *
 * then set ZABBIX_API_URL=http://127.0.0.1:3002/api_jsonrpc.php and
 * ZABBIX_API_TOKEN=mock-token. Environment knobs:
 *   MOCK_ZABBIX_VERSION=6.0.30   pretend to be this version
 *   MOCK_ZABBIX_OK=1             report no problems at all
 * Stop the server to see how the page behaves when Zabbix is unreachable.
 */

require __DIR__.'/../Fixtures/ZabbixFixtures.php';

// The fixtures use collect(); keep this script dependency-light.
require __DIR__.'/../../vendor/autoload.php';

use Tests\Fixtures\ZabbixFixtures;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['mock' => 'zabbix', 'hint' => 'POST JSON-RPC here']);

    return;
}

$request = json_decode(file_get_contents('php://input'), true) ?: [];
$bearer = null;

if (preg_match('/^Bearer\s+(.+)$/i', $_SERVER['HTTP_AUTHORIZATION'] ?? '', $m)) {
    $bearer = $m[1];
}

echo json_encode(ZabbixFixtures::respond(
    $request,
    getenv('MOCK_ZABBIX_VERSION') ?: '6.0.30',
    (bool) getenv('MOCK_ZABBIX_OK'),
    'mock-token',
    $bearer,
));
