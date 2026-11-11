<?php

namespace Tests\Feature;

use App\Filament\Support\ZabbixLookup;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class ZabbixLookupTest extends TestCase
{
    use FakesZabbix;

    public function test_host_search_matches_technical_and_visible_names(): void
    {
        $this->fakeZabbix();

        $byVisible = ZabbixLookup::hosts('vpn gate');
        $this->assertSame('VPN gateway (vpn-gw.example.net)', $byVisible['vpn-gw.example.net']);

        $byTechnical = ZabbixLookup::hosts('web01');
        $this->assertSame('Website (web01.example.net)', $byTechnical['web01.example.net']);
        // What was typed stays selectable.
        $this->assertArrayHasKey('web01', $byTechnical);

        $call = collect($this->zabbixRequests)->last(fn ($r) => $r['body']['method'] === 'host.get');
        $this->assertTrue($call['body']['params']['searchByAny']);
    }

    public function test_host_group_search(): void
    {
        $this->fakeZabbix('7.0.5');

        $this->assertSame(['Databases' => 'Databases', 'data' => 'data (as typed)'], ZabbixLookup::hostGroups('data'));
    }

    public function test_short_searches_do_not_call_zabbix(): void
    {
        $this->fakeZabbix();

        $this->assertSame([], ZabbixLookup::hosts('w'));
        $this->assertSame([], $this->zabbixRequests);
    }

    public function test_falls_back_to_typed_value_when_zabbix_is_down(): void
    {
        $this->zabbixDown();

        $this->assertSame(
            ['web01' => 'web01 (not found in Zabbix – use as typed)'],
            ZabbixLookup::hosts('web01'),
        );
    }
}
