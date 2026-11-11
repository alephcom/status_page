<?php

namespace Tests\Concerns;

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\ZabbixFixtures;

/**
 * Routes Zabbix API calls to the shared fixtures. The fake is registered once
 * per test; later calls only change how it answers (Http::fake stubs stack,
 * so re-registering would leave the first one in charge).
 */
trait FakesZabbix
{
    /** @var list<array<string, mixed>> */
    protected array $zabbixRequests = [];

    private bool $zabbixFaked = false;

    private string $zabbixVersion = '6.0.30';

    private bool $zabbixOk = false;

    private bool $zabbixIsDown = false;

    protected function fakeZabbix(string $version = '6.0.30', bool $ok = false): void
    {
        $this->zabbixVersion = $version;
        $this->zabbixOk = $ok;
        $this->zabbixIsDown = false;
        $this->registerZabbixFake();
    }

    protected function zabbixDown(): void
    {
        $this->zabbixIsDown = true;
        $this->registerZabbixFake();
    }

    private function registerZabbixFake(): void
    {
        if ($this->zabbixFaked) {
            return;
        }

        $this->zabbixFaked = true;

        Http::fake([
            'zabbix.test/*' => function (Request $request) {
                if ($this->zabbixIsDown) {
                    return Http::failedConnection();
                }

                $body = json_decode($request->body(), true);
                $this->zabbixRequests[] = ['body' => $body, 'headers' => $request->headers()];
                $bearer = preg_match('/^Bearer\s+(.+)$/', $request->header('Authorization')[0] ?? '', $m) ? $m[1] : null;

                return Http::response(ZabbixFixtures::respond($body, $this->zabbixVersion, $this->zabbixOk, 'mock-token', $bearer));
            },
        ]);
    }
}
