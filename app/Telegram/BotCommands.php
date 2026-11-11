<?php

namespace App\Telegram;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\TelegramChat;
use Illuminate\Support\Facades\Cache;

/**
 * Handles updates Telegram POSTs to the webhook:
 * /start [payload], /stop, /status, /help, and the bot being removed.
 */
class BotCommands
{
    public function __construct(private readonly TelegramClient $telegram) {}

    /** @param  array<string, mixed>  $update */
    public function handle(array $update): void
    {
        if (isset($update['my_chat_member'])) {
            $this->membershipChanged($update['my_chat_member']);

            return;
        }

        $message = $update['message'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if (! $message || ! str_starts_with($text, '/')) {
            return;
        }

        $chat = $message['chat'];
        [$command, $argument] = array_pad(explode(' ', $text, 2), 2, '');
        // In groups commands arrive as /start@BotName.
        $command = strtolower(explode('@', $command, 2)[0]);

        match ($command) {
            '/start', '/subscribe' => $this->start($chat, trim($argument)),
            '/stop', '/unsubscribe' => $this->stop($chat),
            '/status' => $this->reply($chat, $this->statusText()),
            '/help' => $this->reply($chat, $this->helpText()),
            // Stay quiet about other commands in groups; they may be for other bots.
            default => ($chat['type'] ?? '') === 'private' ? $this->reply($chat, $this->helpText()) : null,
        };
    }

    /** Remember components chosen on the website for the /start deep link. */
    public static function rememberSelection(array $componentIds): string
    {
        $key = bin2hex(random_bytes(12));
        Cache::put("telegram-start:{$key}", array_values(array_map('intval', $componentIds)), now()->addHour());

        return $key;
    }

    /** @param  array<string, mixed>  $chat */
    private function start(array $chat, string $payload): void
    {
        $record = TelegramChat::query()->firstOrNew(['chat_id' => (string) $chat['id']]);
        $isNew = ! $record->exists;
        $record->fill(['type' => $chat['type'] ?? null, 'title' => $this->chatTitle($chat), 'last_error' => null])->save();

        $selection = $payload !== '' ? Cache::pull("telegram-start:{$payload}") : null;

        if (is_array($selection)) {
            $record->components()->sync(Component::query()->public()->whereIn('id', $selection)->pluck('id'));
        }

        $names = $record->components()->orderBy('name')->pluck('name');
        $site = TelegramMessage::escape(Setting::get('site_name'));
        $scope = $names->isEmpty() ? 'all components' : TelegramMessage::escape($names->join(', '));

        $this->reply($chat, ($isNew ? "You're subscribed to <b>{$site}</b> updates" : "You're already subscribed to <b>{$site}</b> updates")
            ." for {$scope}.\n\n".$this->helpText(withIntro: false));
    }

    /** @param  array<string, mixed>  $chat */
    private function stop(array $chat): void
    {
        $deleted = TelegramChat::query()->where('chat_id', (string) $chat['id'])->delete();

        $this->reply($chat, $deleted
            ? 'You have been unsubscribed. Send /start to subscribe again.'
            : 'This chat is not subscribed. Send /start to subscribe.');
    }

    /** @param  array<string, mixed>  $member */
    private function membershipChanged(array $member): void
    {
        if (in_array($member['new_chat_member']['status'] ?? null, ['kicked', 'left'], true)) {
            TelegramChat::query()->where('chat_id', (string) $member['chat']['id'])->delete();
        }
    }

    private function statusText(): string
    {
        $components = Component::query()->enabled()->public()->orderBy('order')->orderBy('name')->get();
        $overall = ComponentStatus::worst($components->pluck('status'));
        $lines = ['<b>'.TelegramMessage::escape(Setting::get('site_name')).'</b>: '.TelegramMessage::escape($overall->getLabel())];

        foreach ($components->reject(fn (Component $c) => $c->status->isOperational()) as $c) {
            $lines[] = '• '.TelegramMessage::escape($c->name).': '.TelegramMessage::escape($c->status->getLabel());
        }

        $incidents = Incident::query()->visible()->unresolved()->latest('started_at')->limit(5)->get();
        if ($incidents->isNotEmpty()) {
            $lines[] = "\n<b>Open incidents</b>";
            foreach ($incidents as $i) {
                $lines[] = '• <a href="'.TelegramMessage::escape(route('incidents.show', $i)).'">'.TelegramMessage::escape($i->title).'</a> ('.TelegramMessage::escape($i->status->getLabel()).')';
            }
        }

        $lines[] = "\n".'<a href="'.TelegramMessage::escape(route('status')).'">Status page</a>';

        return implode("\n", $lines);
    }

    private function helpText(bool $withIntro = true): string
    {
        $intro = $withIntro ? 'I post incident and maintenance updates for <b>'.TelegramMessage::escape(Setting::get('site_name'))."</b>.\n\n" : '';

        return $intro."/status – current status\n/stop – unsubscribe\n/start – subscribe\n\n"
            .'To pick components, use <a href="'.TelegramMessage::escape(route('subscribe')).'">the subscribe page</a>.';
    }

    /** @param  array<string, mixed>  $chat */
    private function chatTitle(array $chat): ?string
    {
        return $chat['title'] ?? (trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')) ?: ($chat['username'] ?? null));
    }

    /** @param  array<string, mixed>  $chat */
    private function reply(array $chat, string $html): void
    {
        $this->telegram->sendMessage((string) $chat['id'], $html);
    }
}
