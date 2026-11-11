<?php

namespace Tests\Feature;

use App\Zabbix\ZabbixClient;
use App\Zabbix\ZabbixException;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class ZabbixClientTest extends TestCase
{
    use FakesZabbix;

    public function test_zabbix_6_0_sends_token_in_body_and_uses_select_groups(): void
    {
        $this->fakeZabbix('6.0.30');

        $triggers = app(ZabbixClient::class)->problemTriggers([['tag' => 'output', 'value' => 'statuspage']]);

        $this->assertNotEmpty($triggers);
        $this->assertArrayHasKey('groups', $triggers[0]);

        $call = collect($this->zabbixRequests)->firstWhere('body.method', 'trigger.get');
        $this->assertSame('mock-token', $call['body']['auth']);
        $this->assertArrayHasKey('selectGroups', $call['body']['params']);
        $this->assertArrayNotHasKey('Authorization', $call['headers']);

        $version = collect($this->zabbixRequests)->firstWhere('body.method', 'apiinfo.version');
        $this->assertArrayNotHasKey('auth', $version['body']);
    }

    public function test_zabbix_7_uses_bearer_header_and_select_host_groups(): void
    {
        $this->fakeZabbix('7.0.5');

        $triggers = app(ZabbixClient::class)->problemTriggers([]);

        $this->assertNotEmpty($triggers);
        $call = collect($this->zabbixRequests)->firstWhere('body.method', 'trigger.get');
        $this->assertArrayNotHasKey('auth', $call['body']);
        $this->assertSame(['Bearer mock-token'], $call['headers']['Authorization']);
        $this->assertArrayHasKey('selectHostGroups', $call['body']['params']);
    }

    public function test_api_errors_become_exceptions(): void
    {
        $this->fakeZabbix('6.0.30');
        $this->expectException(ZabbixException::class);
        $this->expectExceptionMessage('Method not found');

        app(ZabbixClient::class)->call('nonsense.get');
    }

    public function test_unreachable_api_becomes_exception(): void
    {
        $this->zabbixDown();
        $this->expectException(ZabbixException::class);

        app(ZabbixClient::class)->version();
    }
}
