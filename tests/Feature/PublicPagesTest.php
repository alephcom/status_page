<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Services\StatusPoller;
use App\Services\UptimeCalculator;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesZabbix;
use Tests\TestCase;

class PublicPagesTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->fakeZabbix();
        app(StatusPoller::class)->poll();
        $this->artisan('statuspage:rollup-uptime')->assertSuccessful();
    }

    public function test_status_page_views_render(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('Major outage')
            ->assertSee('Website unavailable')
            ->assertSee('DNS server upgrade')
            ->assertSee('Slow e-mail delivery')
            ->assertDontSee('Build disk full')
            ->assertDontSee('Monitoring data may be out of date');

        $this->get('/?view=compact')->assertOk()->assertSee('Website');
        $this->get('/?view=micro')->assertOk()->assertSee('Website');
    }

    public function test_header_shows_the_logo_when_one_is_set(): void
    {
        $this->get('/')->assertOk()->assertDontSee('<img', false);

        Setting::put('logo_url', 'https://cdn.example.com/logo.png');

        $this->get('/')->assertOk()->assertSee('<img src="https://cdn.example.com/logo.png" alt="Example Co. status">', false);
    }

    public function test_incident_history_feed_and_api(): void
    {
        $incident = Incident::query()->where('title', 'Website unavailable')->first();

        $this->get(route('incidents.show', $incident))->assertOk()->assertSee('failed load balancer');
        $this->get('/history')->assertOk()->assertSee('Slow e-mail delivery');
        $this->get('/feed.atom')->assertOk()->assertHeader('Content-Type', 'application/atom+xml; charset=UTF-8')->assertSee('<feed', false);

        $this->getJson('/api/v1/status')->assertOk()
            ->assertJsonPath('status', 'major_outage')
            ->assertJsonPath('stale', false)
            ->assertJsonCount(6, 'components');
        $this->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_hidden_incidents_are_not_public(): void
    {
        $incident = Incident::query()->first();
        $incident->update(['visible' => false]);

        $this->get(route('incidents.show', $incident))->assertNotFound();
        $this->getJson("/api/v1/incidents/{$incident->id}")->assertNotFound();
    }

    public function test_markdown_in_updates_is_escaped(): void
    {
        $incident = Incident::query()->first();
        $incident->updates()->first()->update(['message' => '**bold** <script>alert(1)</script>']);

        $this->get(route('incidents.show', $incident))->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('<strong>bold</strong>', false);
    }

    public function test_uptime_bars_cover_configured_days(): void
    {
        $component = Component::query()->where('name', 'Website')->first();
        $history = app(UptimeCalculator::class)->history($component, 90);

        $this->assertCount(90, $history);
        $this->assertNotNull(end($history)['row']);
    }
}
