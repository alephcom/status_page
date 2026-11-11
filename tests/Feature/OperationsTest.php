<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Component;
use App\Models\ComponentStatusChange;
use App\Models\ComponentStatusDaily;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\MonitoringAlert;
use App\Services\MonitoringWatchdog;
use App\Services\SlaReport;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_sla_report(): void
    {
        $c = Component::create(['name' => 'Portal', 'sla_target' => 99.9]);
        $month = CarbonImmutable::parse('2026-10-01');
        ComponentStatusDaily::create(['component_id' => $c->id, 'date' => '2026-10-01', 'operational_seconds' => 86400 - 3600, 'major_outage_seconds' => 3600]);
        ComponentStatusDaily::create(['component_id' => $c->id, 'date' => '2026-10-02', 'operational_seconds' => 86400]);
        ComponentStatusDaily::create(['component_id' => $c->id, 'date' => '2026-11-01', 'major_outage_seconds' => 86400]); // other month

        $row = app(SlaReport::class)->forMonth($month)->firstWhere(fn ($r) => $r['component']->is($c));

        $this->assertSame(97.917, $row['uptime']);
        $this->assertFalse($row['met']);
        $this->assertSame(60, $row['downtime_minutes']);
        $this->assertSame(2, $row['days']);
        $this->assertStringContainsString('2026-10,Portal,,97.917,99.9,no,60,0,0,2', app(SlaReport::class)->csv($month));
    }

    public function test_watchdog_alerts_admins_once_and_on_recovery(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $editor = User::factory()->create(['role' => UserRole::Editor]);
        Component::create(['name' => 'Portal'])->sources()->create(['driver' => 'zabbix_host', 'config' => ['hosts' => ['a']]]);
        Setting::putMany(['zabbix_last_ok_at' => now()->subMinutes(20)->toIso8601String(), 'zabbix_last_error' => 'timeout']);

        $watchdog = app(MonitoringWatchdog::class);
        $watchdog->check();
        $watchdog->check();

        Notification::assertSentToTimes($admin, MonitoringAlert::class, 1);
        Notification::assertNotSentTo($editor, MonitoringAlert::class);

        Setting::put('zabbix_last_ok_at', now()->toIso8601String());
        $watchdog->check();

        Notification::assertSentToTimes($admin, MonitoringAlert::class, 2);
        Notification::assertSentTo($admin, MonitoringAlert::class, fn (MonitoringAlert $a) => $a->recovered);
    }

    public function test_prune_keeps_baseline_and_recent_data(): void
    {
        $c = Component::create(['name' => 'Portal']);
        ComponentStatusChange::query()->delete();
        foreach ([500, 450, 10] as $daysAgo) {
            $c->statusChanges()->create(['status' => 'operational', 'changed_at' => now()->subDays($daysAgo)]);
        }
        Subscriber::create(['email' => 'old@example.test'])->forceFill(['created_at' => now()->subDays(30)])->save();
        Subscriber::create(['email' => 'new@example.test']);
        AuditLog::query()->update(['created_at' => now()->subYears(2)]);

        $this->artisan('statuspage:prune')->assertSuccessful();

        $this->assertSame(2, $c->statusChanges()->count()); // 450 days (baseline) + 10 days
        $this->assertSame(['new@example.test'], Subscriber::query()->pluck('email')->all());
        $this->assertSame(0, AuditLog::query()->where('created_at', '<', now()->subYear())->count());
    }
}
