<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\ComponentVisibility;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Component;
use App\Models\ComponentMetric;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\User;
use App\Services\IncidentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicExtrasTest extends TestCase
{
    use RefreshDatabase;

    public function test_badges(): void
    {
        $c = Component::create(['name' => 'Portal']);
        $c->update(['manual_status' => ComponentStatus::MajorOutage]);
        $hidden = Component::create(['name' => 'Internal', 'visibility' => ComponentVisibility::Private]);

        $this->get('/badge.svg')->assertOk()->assertHeader('Content-Type', 'image/svg+xml; charset=UTF-8')->assertSee('major outage');
        $this->get("/badge/{$c->id}.svg?label=portal")->assertOk()->assertSee('portal: major outage', false);
        $this->get("/badge/{$hidden->id}.svg")->assertNotFound();
    }

    public function test_maintenance_calendar(): void
    {
        $c = Component::create(['name' => 'DB']);
        $m = Maintenance::create(['title' => 'Upgrade, part 1', 'message' => 'Short; sweet', 'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHour()]);
        $m->components()->attach($c);
        Maintenance::create(['title' => 'Hidden', 'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDays(2), 'visible' => false]);

        $body = $this->get('/maintenance.ics')->assertOk()->assertHeader('Content-Type', 'text/calendar; charset=UTF-8')->getContent();

        $this->assertStringStartsWith("BEGIN:VCALENDAR\r\n", $body);
        $this->assertStringContainsString('SUMMARY:Maintenance: Upgrade\, part 1', $body);
        $this->assertStringContainsString('DTSTART:'.$m->scheduled_start->utc()->format('Ymd\THis\Z'), $body);
        $this->assertStringNotContainsString('Hidden', $body);
        foreach (explode("\r\n", $body) as $line) {
            $this->assertLessThanOrEqual(75, strlen($line));
        }
    }

    public function test_feed_can_be_limited_to_components(): void
    {
        $a = Component::create(['name' => 'Alpha']);
        $b = Component::create(['name' => 'Beta']);
        $manager = app(IncidentManager::class);
        $manager->create(['title' => 'Alpha broke', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor], [$a->id], 'x');
        $manager->create(['title' => 'Beta broke', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor], [$b->id], 'x');
        $manager->create(['title' => 'Everything', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor], [], 'x');

        $this->get("/feed.atom?components={$a->id}")->assertOk()
            ->assertSee('Alpha broke')->assertSee('Everything')->assertDontSee('Beta broke');
    }

    public function test_embed_script(): void
    {
        $this->get('/embed.js')->assertOk()
            ->assertHeader('Content-Type', 'application/javascript; charset=UTF-8')
            ->assertSee('/api/v1/status', false);
    }

    public function test_private_components_are_hidden_from_the_public_but_shown_to_staff_and_allowed_networks(): void
    {
        Component::create(['name' => 'Portal']);
        $internal = Component::create(['name' => 'Build farm', 'visibility' => ComponentVisibility::Private]);
        $incident = app(IncidentManager::class)->create(['title' => 'Builds failing', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Major], [$internal->id], 'x');

        $this->get('/')->assertOk()->assertSee('Portal')->assertDontSee('Build farm')->assertDontSee('Builds failing');
        $this->get(route('incidents.show', $incident))->assertNotFound();
        $this->getJson('/api/v1/components')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/status')->assertJsonCount(0, 'active_incidents');

        Setting::put('private_ip_ranges', ['127.0.0.0/8']);
        $this->get('/')->assertSee('Build farm')->assertSee('Builds failing');
        $this->getJson('/api/v1/components')->assertJsonCount(2, 'data');

        Setting::put('private_ip_ranges', []);
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));
        $this->get('/')->assertSee('Build farm');
        $this->get(route('incidents.show', $incident))->assertOk();
    }

    public function test_announcement_branding_and_footer_links(): void
    {
        Setting::putMany([
            'announcement' => 'Planned **move** on Friday.',
            'announcement_level' => 'warning',
            'brand_color' => '#123abc',
            'footer_links' => [['name' => 'Privacy', 'url' => 'https://example.test/privacy']],
            'default_theme' => 'dark',
        ]);

        $this->get('/')->assertOk()
            ->assertSee('<strong>move</strong>', false)
            ->assertSee('announcement is-warning', false)
            ->assertSee('--brand: #123abc', false)
            ->assertSee('https://example.test/privacy')
            ->assertSee('data-theme="dark"', false);

        Setting::put('announcement_until', now()->subMinute()->toDateTimeString());
        $this->get('/')->assertDontSee('Planned');
    }

    public function test_language_switching(): void
    {
        Setting::putMany(['enabled_locales' => ['en', 'de'], 'default_locale' => 'en']);

        $this->get('/')->assertSee('All systems operational');
        $this->get('/', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertSee('Alle Systeme betriebsbereit');
        $this->get('/?lang=de')->assertSee('Alle Systeme betriebsbereit')->assertCookie('statuspage_lang');
        $this->get('/', ['Accept-Language' => 'fr'])->assertSee('All systems operational'); // French not enabled
    }

    public function test_metric_chart_is_rendered_with_table_view(): void
    {
        $c = Component::create(['name' => 'Portal']);
        $metric = ComponentMetric::create(['component_id' => $c->id, 'name' => 'Response time', 'host' => 'web', 'item_key' => 'k', 'unit' => 'ms']);
        foreach ([120, 180, 150] as $i => $v) {
            $metric->points()->create(['recorded_at' => now()->subMinutes(30 - $i * 10), 'value' => $v]);
        }

        $this->get('/')->assertOk()
            ->assertSee('Response time')
            ->assertSee('150 ms')
            ->assertSee('class="metric-line"', false)
            ->assertSee('Show as table');
    }

    public function test_health_endpoint(): void
    {
        $this->getJson('/health')->assertStatus(503)->assertJsonPath('checks.scheduler.ok', false);

        Setting::putMany(['last_poll_at' => now()->toIso8601String(), 'zabbix_last_ok_at' => now()->toIso8601String()]);

        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
    }
}
