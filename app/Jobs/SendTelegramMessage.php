<?php

namespace App\Jobs;

use App\Models\TelegramChat;
use App\Telegram\TelegramClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Send one message to one chat. Chats that blocked or removed the bot are
 * dropped (unless an admin added them, in which case the error is shown in
 * the admin panel).
 */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    // Rate-limit and 429 releases count as attempts, so bound by time and
    // by real failures rather than by attempts.
    public int $tries = 0;

    public int $maxExceptions = 5;

    /** @var list<int> */
    public array $backoff = [5, 30, 120, 600];

    public bool $deleteWhenMissingModels = true;

    public function __construct(public TelegramChat $chat, public string $html) {}

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHours(2);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        // Telegram allows about 30 messages per second per bot.
        return [new RateLimited('telegram')];
    }

    public function handle(TelegramClient $telegram): void
    {
        $response = $telegram->sendMessage($this->chat->chat_id, $this->html);

        if ($response->successful()) {
            if ($this->chat->last_error !== null) {
                $this->chat->forceFill(['last_error' => null])->saveQuietly();
            }

            return;
        }

        $description = (string) $response->json('description', 'HTTP '.$response->status());

        if ($response->status() === 429) {
            $this->release((int) $response->json('parameters.retry_after', 5));

            return;
        }

        // Group upgraded to a supergroup: the chat id changed.
        if ($newId = $response->json('parameters.migrate_to_chat_id')) {
            $this->chat->forceFill(['chat_id' => (string) $newId])->saveQuietly();
            $this->release(1);

            return;
        }

        // 403: blocked or kicked; 400 "chat not found": gone. Retrying won't help.
        if (in_array($response->status(), [400, 403], true)) {
            if ($this->chat->added_by_admin) {
                $this->chat->forceFill(['last_error' => mb_substr($description, 0, 255)])->saveQuietly();
            } else {
                $this->chat->delete();
            }

            return;
        }

        $response->throw();
    }
}
