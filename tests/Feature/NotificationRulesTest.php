<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\ComponentVisibility;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\WebhookEvent;
use App\Jobs\SendWebhook;
use App\Models\Component;
use App\Models\Subscriber;
use App\Models\Webhook;
use App\Notifications\StatusNotification;
use App\Services\IncidentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationRulesTest extends TestCase
{
    use RefreshDatabase;

    private function open(IncidentImpact $impact, array $components = []): void
    {
        app(IncidentManager::class)->create(['title' => 'Problem', 'status' => IncidentStatus::Investigating, 'impact' => $impact], $components, 'Looking.');
    }

    public function test_minimum_impact_filters_incident_notifications(): void
    {
        Notification::fake();
        $all = Subscriber::create(['email' => 'all@example.test', 'verified_at' => now()]);
        $major = Subscriber::create(['email' => 'major@example.test', 'verified_at' => now(), 'min_impact' => 'major']);
        $critical = Subscriber::create(['email' => 'crit@example.test', 'verified_at' => now(), 'min_impact' => 'critical']);

        $this->open(IncidentImpact::Minor);
        $this->open(IncidentImpact::Major);

        Notification::assertSentToTimes($all, StatusNotification::class, 2);
        Notification::assertSentToTimes($major, StatusNotification::class, 1);
        Notification::assertNotSentTo($critical, StatusNotification::class);
    }

    public function test_incidents_only_on_private_components_are_not_sent_to_subscribers(): void
    {
        Notification::fake();
        Bus::fake([SendWebhook::class]);
        Webhook::create(['name' => 'w', 'url' => 'https://hook.test', 'events' => [WebhookEvent::IncidentCreated->value]]);
        $internal = Component::create(['name' => 'Build farm', 'visibility' => ComponentVisibility::Private]);
        $public = Component::create(['name' => 'Portal']);
        $sub = Subscriber::create(['email' => 's@example.test', 'verified_at' => now()]);

        $this->open(IncidentImpact::Major, [$internal->id]);
        Notification::assertNothingSent();
        Bus::assertDispatchedTimes(SendWebhook::class, 1); // integrations still hear about it

        $this->open(IncidentImpact::Major, [$internal->id, $public->id]);
        Notification::assertSentTo($sub, StatusNotification::class, function (StatusNotification $n) {
            $text = implode("\n", $n->lines);

            return str_contains($text, 'Portal') && ! str_contains($text, 'Build farm');
        });
    }

    public function test_discord_webhook_gets_an_embed_without_signature(): void
    {
        Http::fake(['discord.test/*' => Http::response('', 204)]);
        $hook = Webhook::create(['name' => 'Discord', 'url' => 'https://discord.test/api/webhooks/1/x', 'events' => [WebhookEvent::IncidentCreated->value], 'format' => 'discord', 'secret' => 'ignored']);
        $c = Component::create(['name' => 'Portal']);

        $this->open(IncidentImpact::Major, [$c->id]);

        Http::assertSent(function (Request $request) {
            $embed = $request->data()['embeds'][0] ?? [];

            return $request->url() === 'https://discord.test/api/webhooks/1/x'
                && str_starts_with($embed['title'], 'New incident: Problem')
                && $embed['description'] === 'Looking.'
                && collect($embed['fields'])->firstWhere('name', 'Affected')['value'] === 'Portal'
                && $request->data()['allowed_mentions'] === ['parse' => []];
        });
        $this->assertSame(204, $hook->fresh()->last_status);
    }

    public function test_component_override_fires_webhook(): void
    {
        Bus::fake([SendWebhook::class]);
        Webhook::create(['name' => 'w', 'url' => 'https://hook.test', 'events' => [WebhookEvent::ComponentOverrideChanged->value]]);
        $c = Component::create(['name' => 'Portal']);

        $c->update(['manual_status' => ComponentStatus::Degraded]);
        $c->update(['manual_status' => null]);

        Bus::assertDispatched(SendWebhook::class, fn ($job) => ($job->data['manual_override']['status'] ?? null) === 'degraded');
        Bus::assertDispatched(SendWebhook::class, fn ($job) => $job->data['manual_override'] === null);
    }

    public function test_subscribe_form_stores_minimum_impact_and_rejects_private_components(): void
    {
        Notification::fake();
        $private = Component::create(['name' => 'Internal', 'visibility' => ComponentVisibility::Private]);

        $this->post(route('subscribe.store'), ['email' => 'x@example.test', 'components' => [$private->id]])
            ->assertSessionHasErrors('components.0');

        $this->post(route('subscribe.store'), ['email' => 'x@example.test', 'min_impact' => 'major'])->assertRedirect();

        $this->assertSame(IncidentImpact::Major, Subscriber::query()->sole()->min_impact);
    }
}
