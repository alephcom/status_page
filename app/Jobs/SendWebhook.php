<?php

namespace App\Jobs;

use App\Models\Webhook;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;

/**
 * POST a JSON event to a webhook. When the webhook has a secret, the body is
 * signed: X-Statuspage-Signature: sha256=<hex hmac of the raw body>.
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
        $body = json_encode([
            'event' => $this->event,
            'sent_at' => now()->toIso8601String(),
            'data' => $this->data,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

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
