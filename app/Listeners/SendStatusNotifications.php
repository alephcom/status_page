<?php

namespace App\Listeners;

use App\Enums\ComponentStatus;
use App\Enums\WebhookEvent;
use App\Events\ComponentStatusChanged;
use App\Events\IncidentPublished;
use App\Events\IncidentUpdatePosted;
use App\Events\MaintenanceScheduled;
use App\Jobs\SendWebhook;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Webhook;
use App\Notifications\StatusNotification;
use App\Support\Payloads;
use Illuminate\Support\Facades\Notification;

class SendStatusNotifications
{
    public function handleIncidentPublished(IncidentPublished $event): void
    {
        $incident = $event->incident->load('components', 'updates');
        $componentIds = $incident->components->pluck('id')->all();

        $lines = array_filter([
            "**Status:** {$incident->status->getLabel()} · **Impact:** {$incident->impact->getLabel()}",
            $incident->components->isNotEmpty() ? '**Affected:** '.$incident->components->pluck('name')->join(', ') : null,
            $incident->updates->first()?->message,
        ]);

        $this->mail($componentIds, new StatusNotification(
            "Incident: {$incident->title}", $incident->title, array_values($lines), 'View incident', route('incidents.show', $incident),
        ));

        $this->webhooks(WebhookEvent::IncidentCreated, Payloads::incident($incident));
    }

    public function handleIncidentUpdatePosted(IncidentUpdatePosted $event): void
    {
        $update = $event->update;
        $incident = $update->incident->load('components', 'updates');

        $this->mail($incident->components->pluck('id')->all(), new StatusNotification(
            "{$update->status->getLabel()}: {$incident->title}",
            $incident->title,
            ["**{$update->status->getLabel()}**", $update->message],
            'View incident',
            route('incidents.show', $incident),
        ));

        $this->webhooks(WebhookEvent::IncidentUpdated, Payloads::incident($incident) + ['update' => Payloads::update($update)]);
    }

    public function handleMaintenanceScheduled(MaintenanceScheduled $event): void
    {
        $m = $event->maintenance->load('components');
        $tz = config('app.timezone');

        $lines = array_filter([
            '**When:** '.$m->scheduled_start->timezone($tz)->toDayDateTimeString().' – '.$m->scheduled_end->timezone($tz)->toDayDateTimeString()." ({$tz})",
            $m->components->isNotEmpty() ? '**Affected:** '.$m->components->pluck('name')->join(', ') : null,
            $m->message,
        ]);

        $this->mail($m->components->pluck('id')->all(), new StatusNotification(
            "Scheduled maintenance: {$m->title}", $m->title, array_values($lines), 'View status page', route('status'),
        ));

        $this->webhooks(WebhookEvent::MaintenanceScheduled, Payloads::maintenance($m));
    }

    public function handleComponentStatusChanged(ComponentStatusChanged $event): void
    {
        $c = $event->component;

        $this->webhooks(WebhookEvent::ComponentStatusChanged, Payloads::component($c) + ['previous_status' => $event->from?->value]);

        if (! Setting::get('notify_component_changes') || $event->from === null) {
            return;
        }

        // Skip churn between "bad" states; only tell people when it breaks or recovers.
        $wasOk = $event->from === ComponentStatus::Operational;
        $isOk = $event->to === ComponentStatus::Operational;

        if ($wasOk === $isOk) {
            return;
        }

        $this->mail([$c->id], new StatusNotification(
            "{$c->name} is now {$event->to->getLabel()}",
            $c->name,
            ["Status changed from **{$event->from->getLabel()}** to **{$event->to->getLabel()}**."],
            'View status page',
            route('status'),
        ));
    }

    /** @param list<int> $componentIds */
    private function mail(array $componentIds, StatusNotification $notification): void
    {
        Subscriber::query()->verified()->interestedIn($componentIds)
            ->chunkById(200, fn ($subscribers) => Notification::send($subscribers, $notification));
    }

    /** @param array<string, mixed> $data */
    private function webhooks(WebhookEvent $event, array $data): void
    {
        Webhook::query()->listeningTo($event)->each(fn (Webhook $w) => SendWebhook::dispatch($w, $event->value, $data));
    }
}
