<?php

namespace Tests\Feature;

use App\Ai\IncidentWriter;
use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\WebhookEvent;
use App\Jobs\SendWebhook;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Webhook;
use App\Notifications\StatusNotification;
use App\Services\IncidentManager;
use App\Services\StatusPoller;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\FakesZabbix;
use Tests\Fakes\FakeIncidentWriter;
use Tests\TestCase;

class IncidentWorkflowTest extends TestCase
{
    use FakesZabbix, RefreshDatabase;

    private function incident(array $attrs = [], array $components = []): Incident
    {
        return app(IncidentManager::class)->create(
            $attrs + ['title' => 'Portal down', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Major],
            $components,
            'Investigating.',
        );
    }

    /** The demo data has an open Website incident, which would stop a draft being opened. */
    private function seedWithoutOpenIncidents(): void
    {
        $this->seed(DemoSeeder::class);
        Incident::query()->unresolved()->each(fn (Incident $i) => app(IncidentManager::class)->postUpdate($i, IncidentStatus::Resolved, 'Done.'));
    }

    public function test_scheduled_update_is_posted_when_due(): void
    {
        Notification::fake();
        $sub = Subscriber::create(['email' => 's@example.test', 'verified_at' => now()]);
        $incident = $this->incident();
        $manager = app(IncidentManager::class);

        $manager->scheduleUpdate($incident, IncidentStatus::Monitoring, 'Fix deployed.', now()->addMinutes(30));

        $this->assertSame(0, $manager->publishDueUpdates());
        $this->assertCount(1, $incident->fresh()->updates);

        $this->travel(31)->minutes();
        $this->assertSame(1, $manager->publishDueUpdates());
        $this->assertSame(0, $manager->publishDueUpdates());

        $incident->refresh();
        $this->assertSame(IncidentStatus::Monitoring, $incident->status);
        $this->assertSame('Fix deployed.', $incident->updates->first()->message);
        $this->assertSame(0, $incident->scheduledUpdates()->count());
        Notification::assertSentToTimes($sub, StatusNotification::class, 2);
    }

    public function test_resolving_fires_incident_resolved_webhook_once(): void
    {
        Bus::fake([SendWebhook::class]);
        Webhook::create(['name' => 'w', 'url' => 'https://hook.test', 'events' => [WebhookEvent::IncidentResolved->value]]);
        $incident = $this->incident();
        $manager = app(IncidentManager::class);

        $manager->postUpdate($incident, IncidentStatus::Resolved, 'Fixed.');
        $manager->postUpdate($incident, IncidentStatus::Resolved, 'Postscript.');

        Bus::assertDispatchedTimes(SendWebhook::class, 1);
        Bus::assertDispatched(SendWebhook::class, fn ($job) => $job->event === 'incident.resolved' && $job->data['update']['message'] === 'Fixed.');
    }

    public function test_hidden_auto_draft_is_resolved_when_monitoring_recovers(): void
    {
        $this->seedWithoutOpenIncidents();
        Setting::put('auto_incidents', true);
        $this->fakeZabbix();

        app(StatusPoller::class)->poll();
        $draft = Incident::query()->where('auto_created', true)->sole();
        $this->assertFalse($draft->visible);

        $this->fakeZabbix(ok: true);
        app(StatusPoller::class)->poll();

        $this->assertTrue($draft->fresh()->isResolved());
    }

    public function test_published_auto_incident_is_flagged_not_resolved(): void
    {
        $this->seedWithoutOpenIncidents();
        Setting::put('auto_incidents', true);
        $this->fakeZabbix();

        app(StatusPoller::class)->poll();
        $incident = Incident::query()->where('auto_created', true)->sole();
        $incident->update(['visible' => true]);

        $this->fakeZabbix(ok: true);
        app(StatusPoller::class)->poll();

        $incident->refresh();
        $this->assertFalse($incident->isResolved());
        $this->assertNotNull($incident->recovered_at);

        // The problem comes back: the flag goes away.
        $this->fakeZabbix();
        app(StatusPoller::class)->poll();
        $this->assertNull($incident->fresh()->recovered_at);
    }

    public function test_auto_draft_text_is_written_by_ai_when_enabled(): void
    {
        $this->seedWithoutOpenIncidents();
        Setting::put('auto_incidents', true);
        $writer = new FakeIncidentWriter;
        $this->app->instance(IncidentWriter::class, $writer);
        $this->fakeZabbix();

        app(StatusPoller::class)->poll();

        $draft = Incident::query()->where('auto_created', true)->sole();
        $this->assertSame('Portal is unavailable', $draft->title);
        $this->assertSame(IncidentImpact::Major, $draft->impact);
        $this->assertSame('Some users cannot sign in. We are looking into it.', $draft->updates()->first()->message);
        $this->assertFalse($draft->visible);

        // The writer saw the monitoring problems.
        $this->assertSame('Website is not responding', $writer->calls[0][1]['components'][0]['monitoring_problems'][0]['name']);
    }

    public function test_auto_draft_keeps_default_text_without_ai(): void
    {
        $this->seedWithoutOpenIncidents();
        Setting::put('auto_incidents', true);
        $this->app->instance(IncidentWriter::class, new FakeIncidentWriter(enabled: false));
        $this->fakeZabbix();

        app(StatusPoller::class)->poll();

        $this->assertStringContainsString('is unavailable', Incident::query()->where('auto_created', true)->sole()->title);
    }

    public function test_published_postmortem_appears_on_incident_page_and_api(): void
    {
        $incident = $this->incident();
        app(IncidentManager::class)->postUpdate($incident, IncidentStatus::Resolved, 'Fixed.');
        $incident->update(['postmortem' => "### Root cause\nA full disk."]);

        $this->get(route('incidents.show', $incident))->assertDontSee('Post-incident review');

        $incident->update(['postmortem_published_at' => now()]);

        $this->get(route('incidents.show', $incident))->assertSee('Post-incident review')->assertSee('A full disk.');
        $this->getJson("/api/v1/incidents/{$incident->id}")->assertJsonPath('data.postmortem', "### Root cause\nA full disk.");
    }

    public function test_template_fills_existing_components_only(): void
    {
        $c = Component::create(['name' => 'DB']);
        $t = IncidentTemplate::create(['name' => 'Failover', 'title' => 'Database failover', 'message' => 'Failing over.', 'impact' => 'major', 'component_status' => 'partial_outage', 'component_ids' => [$c->id, 999]]);

        $this->assertSame([$c->id], $t->existingComponentIds());
        $this->assertSame(ComponentStatus::PartialOutage, $t->component_status);
    }
}
