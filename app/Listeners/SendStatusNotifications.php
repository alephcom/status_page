<?php

namespace App\Listeners;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\WebhookEvent;
use App\Events\ComponentOverrideChanged;
use App\Events\ComponentStatusChanged;
use App\Events\IncidentPublished;
use App\Events\IncidentResolved;
use App\Events\IncidentUpdatePosted;
use App\Events\MaintenancePhaseReached;
use App\Events\MaintenanceScheduled;
use App\Jobs\SendTelegramMessage;
use App\Jobs\SendWebhook;
use App\Mcp\McpPayloads;
use App\Models\Component;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\TelegramChat;
use App\Models\Webhook;
use App\Notifications\StatusNotification;
use App\Support\Payloads;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramMessage;
use Illuminate\Support\Facades\Notification;

class SendStatusNotifications
{
    public function handleIncidentPublished(IncidentPublished $event): void
    {
        $incident = $event->incident->load('components', 'updates');
        $componentIds = $incident->components->pluck('id')->all();
        $public = $incident->components->reject->isPrivate();

        $lines = array_filter([
            "**Status:** {$incident->status->getLabel()} · **Impact:** {$incident->impact->getLabel()}",
            $public->isNotEmpty() ? '**Affected:** '.$public->pluck('name')->join(', ') : null,
            $incident->updates->first()?->message,
        ]);

        $this->mail($componentIds, new StatusNotification(
            "Incident: {$incident->title}", $incident->title, array_values($lines), 'View incident', route('incidents.show', $incident),
        ), $incident->impact);

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
        ), $incident->impact);

        $this->webhooks(WebhookEvent::IncidentUpdated, Payloads::incident($incident) + ['update' => Payloads::update($update)]);
    }

    /** Subscribers already got the "Resolved" update; this is for integrations. */
    public function handleIncidentResolved(IncidentResolved $event): void
    {
        $incident = $event->incident->load('components', 'updates');

        $this->webhooks(WebhookEvent::IncidentResolved, Payloads::incident($incident) + ['update' => Payloads::update($incident->updates->first())]);
    }

    public function handleMaintenanceScheduled(MaintenanceScheduled $event): void
    {
        $m = $event->maintenance->load('components');

        $this->mail($m->components->pluck('id')->all(), new StatusNotification(
            "Scheduled maintenance: {$m->title}", $m->title, $this->maintenanceLines($m, withMessage: true), 'View status page', route('status'),
        ));

        $this->webhooks(WebhookEvent::MaintenanceScheduled, Payloads::maintenance($m));
    }

    public function handleMaintenancePhaseReached(MaintenancePhaseReached $event): void
    {
        $m = $event->maintenance->load('components');

        $webhook = match ($event->phase) {
            MaintenancePhaseReached::STARTED => WebhookEvent::MaintenanceStarted,
            MaintenancePhaseReached::COMPLETED => WebhookEvent::MaintenanceCompleted,
            default => null,
        };

        if ($webhook) {
            $this->webhooks($webhook, Payloads::maintenance($m));
        }

        if ($event->phase !== MaintenancePhaseReached::REMINDER && ! Setting::get('notify_maintenance_lifecycle')) {
            return;
        }

        $subject = match ($event->phase) {
            MaintenancePhaseReached::REMINDER => 'Maintenance starts '.$m->scheduled_start->diffForHumans(['parts' => 2]).": {$m->title}",
            MaintenancePhaseReached::STARTED => "Maintenance started: {$m->title}",
            default => "Maintenance completed: {$m->title}",
        };

        $this->mail($m->components->pluck('id')->all(), new StatusNotification(
            $subject, $m->title, $this->maintenanceLines($m, withMessage: $event->phase === MaintenancePhaseReached::REMINDER), 'View status page', route('status'),
        ));
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

        $impact = in_array(ComponentStatus::MajorOutage, [$event->from, $event->to], true) ? IncidentImpact::Major : IncidentImpact::Minor;

        $this->mail([$c->id], new StatusNotification(
            "{$c->name} is now {$event->to->getLabel()}",
            $c->name,
            ["Status changed from **{$event->from->getLabel()}** to **{$event->to->getLabel()}**."],
            'View status page',
            route('status'),
        ), $impact);
    }

    public function handleComponentOverrideChanged(ComponentOverrideChanged $event): void
    {
        $this->webhooks(WebhookEvent::ComponentOverrideChanged, McpPayloads::component($event->component));
    }

    /** @return list<string> */
    private function maintenanceLines($m, bool $withMessage): array
    {
        $tz = config('app.timezone');
        $public = $m->components->reject->isPrivate();

        return array_values(array_filter([
            '**When:** '.$m->scheduled_start->timezone($tz)->toDayDateTimeString().' – '.$m->scheduled_end->timezone($tz)->toDayDateTimeString()." ({$tz})",
            $public->isNotEmpty() ? '**Affected:** '.$public->pluck('name')->join(', ') : null,
            $withMessage ? $m->message : null,
        ]));
    }

    /**
     * Send to e-mail subscribers and Telegram chats. Subscribers are the
     * public, so something that only touches private components is not sent.
     *
     * @param  list<int>  $componentIds
     */
    private function mail(array $componentIds, StatusNotification $notification, ?IncidentImpact $impact = null): void
    {
        if ($componentIds !== []) {
            $componentIds = Component::query()->whereIn('id', $componentIds)->public()->pluck('id')->all();

            if ($componentIds === []) {
                return;
            }
        }

        Subscriber::query()->verified()->interestedIn($componentIds)->wantsImpact($impact)
            ->chunkById(200, fn ($subscribers) => Notification::send($subscribers, $notification));

        if (TelegramClient::enabled()) {
            $html = TelegramMessage::fromNotification($notification);

            TelegramChat::query()->interestedIn($componentIds)->wantsImpact($impact)
                ->chunkById(200, fn ($chats) => $chats->each(function (TelegramChat $chat) use ($html): void {
                    SendTelegramMessage::dispatch($chat, $html);
                }));
        }
    }

    /** @param array<string, mixed> $data */
    private function webhooks(WebhookEvent $event, array $data): void
    {
        Webhook::query()->listeningTo($event)->each(fn (Webhook $w) => SendWebhook::dispatch($w, $event->value, $data));
    }
}
