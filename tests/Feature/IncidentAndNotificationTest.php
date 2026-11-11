<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\WebhookEvent;
use App\Models\Component;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Webhook;
use App\Notifications\StatusNotification;
use App\Notifications\VerifySubscription;
use App\Services\IncidentManager;
use App\Services\MaintenanceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class IncidentAndNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_incident_component_status_applies_while_open(): void
    {
        $c = Component::create(['name' => 'Portal']);
        $manager = app(IncidentManager::class);

        $incident = $manager->create([
            'title' => 'Portal slow', 'status' => IncidentStatus::Investigating,
            'impact' => IncidentImpact::Minor, 'component_status' => ComponentStatus::Degraded,
        ], [$c->id], 'Looking into it.');

        $this->assertSame(ComponentStatus::Degraded, $c->fresh()->status);

        $manager->postUpdate($incident, IncidentStatus::Resolved, 'Fixed.');

        $this->assertNotNull($incident->fresh()->resolved_at);
        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);
        $this->assertCount(2, $incident->fresh()->updates);
    }

    public function test_subscribers_are_notified_only_for_their_components(): void
    {
        Notification::fake();
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);

        $all = Subscriber::create(['email' => 'all@example.test', 'verified_at' => now()]);
        $onlyB = Subscriber::create(['email' => 'b@example.test', 'verified_at' => now()]);
        $onlyB->components()->attach($b);
        $unverified = Subscriber::create(['email' => 'new@example.test']);

        app(IncidentManager::class)->create(['title' => 'A broke', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor], [$a->id], 'Hmm.');

        Notification::assertSentTo($all, StatusNotification::class);
        Notification::assertNotSentTo($onlyB, StatusNotification::class);
        Notification::assertNotSentTo($unverified, StatusNotification::class);
    }

    public function test_hidden_incident_notifies_only_when_published(): void
    {
        Notification::fake();
        $sub = Subscriber::create(['email' => 'x@example.test', 'verified_at' => now()]);
        $manager = app(IncidentManager::class);

        $incident = $manager->create(['title' => 'Draft', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor, 'visible' => false], [], 'Draft.');
        Notification::assertNothingSent();

        $incident->update(['visible' => true]);
        $manager->edited($incident, false);
        Notification::assertSentToTimes($sub, StatusNotification::class, 1);
    }

    public function test_maintenance_notifies_once(): void
    {
        Notification::fake();
        $sub = Subscriber::create(['email' => 'x@example.test', 'verified_at' => now()]);
        $m = Maintenance::create(['title' => 'Upgrade', 'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHour()]);

        app(MaintenanceManager::class)->saved($m);
        app(MaintenanceManager::class)->saved($m->fresh());

        Notification::assertSentToTimes($sub, StatusNotification::class, 1);
    }

    public function test_webhooks_are_signed(): void
    {
        Http::fake(['hooks.test/*' => Http::response('', 204)]);
        Webhook::create(['name' => 'Chat', 'url' => 'https://hooks.test/in', 'secret' => 's3cret', 'events' => [WebhookEvent::IncidentCreated->value]]);

        app(IncidentManager::class)->create(['title' => 'Hook me', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor], [], 'Hi.');

        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://hooks.test/in'
                && $request['event'] === 'incident.created'
                && $request->header('X-Statuspage-Signature')[0] === 'sha256='.hash_hmac('sha256', $request->body(), 's3cret');
        });
        $this->assertSame(204, Webhook::first()->last_status);
    }

    public function test_component_change_mail_is_opt_in(): void
    {
        Notification::fake();
        $sub = Subscriber::create(['email' => 'x@example.test', 'verified_at' => now()]);
        $c = Component::create(['name' => 'Portal']);

        $c->update(['manual_status' => ComponentStatus::MajorOutage]);
        Notification::assertNothingSent();

        Setting::put('notify_component_changes', true);
        $c->update(['manual_status' => null]);
        Notification::assertSentTo($sub, StatusNotification::class);
    }

    public function test_subscription_double_opt_in(): void
    {
        Notification::fake();
        $c = Component::create(['name' => 'Portal']);

        $this->post('/subscribe', ['email' => 'Me@Example.test', 'components' => [$c->id]])->assertRedirect('/subscribe');

        $sub = Subscriber::query()->where('email', 'me@example.test')->firstOrFail();
        $this->assertFalse($sub->isVerified());
        Notification::assertSentTo($sub, VerifySubscription::class);

        $this->get(route('subscription.verify', $sub))->assertRedirect(route('subscription.show', $sub));
        $this->assertTrue($sub->fresh()->isVerified());

        // A verified subscription cannot be changed from the public form.
        $this->post('/subscribe', ['email' => 'me@example.test', 'components' => []]);
        $this->assertSame([$c->id], $sub->components()->pluck('components.id')->all());

        $this->delete(route('subscription.destroy', $sub))->assertRedirect('/');
        $this->assertModelMissing($sub);
    }
}
