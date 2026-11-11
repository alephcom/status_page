<?php

namespace App\Jobs;

use App\Enums\WebhookFormat;
use App\Models\Webhook;
use App\Support\DiscordPayload;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * POST an event to a webhook: our own JSON envelope, or a Discord embed.
 * When the webhook has a secret, the body is signed:
 * X-Statuspage-Signature: sha256=<hex hmac of the raw body>.
 */
class SendWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 60, 300];

    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public Webhook $webhook,
        public string $event,
        public array $data,
    ) {}

    public function handle(): void
    {
        $payload = $this->webhook->format === WebhookFormat::Discord
            ? DiscordPayload::make($this->event, $this->data)
            : ['event' => $this->event, 'sent_at' => now()->toIso8601String(), 'data' => $this->data];

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $headers = ['X-Statuspage-Event' => $this->event];

        if (filled($this->webhook->secret)) {
            $headers['X-Statuspage-Signature'] = 'sha256='.hash_hmac('sha256', $body, $this->webhook->secret);
        }

        $response = Http::timeout(10)
            ->withHeaders($headers)
            ->withBody($body, 'application/json')
            ->post($this->webhook->url);

        $this->webhook->forceFill(['last_status' => $response->status(), 'last_sent_at' => now()])->saveQuietly();

        $response->throw();
    }
}
