<?php

namespace App\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Minimal Telegram Bot API client. */
class TelegramClient
{
    public static function enabled(): bool
    {
        return filled(config('services.telegram.bot_token')) && filled(config('services.telegram.bot_username'));
    }

    public static function botUsername(): string
    {
        return ltrim((string) config('services.telegram.bot_username'), '@');
    }

    /** Link that opens the bot and sends /start with the payload. */
    public static function startLink(?string $payload = null): string
    {
        return 'https://t.me/'.self::botUsername().($payload ? '?start='.$payload : '');
    }

    /**
     * Secret Telegram echoes in X-Telegram-Bot-Api-Secret-Token, so the
     * webhook can tell real updates from forged ones. Derived from the app
     * key so there is nothing extra to configure.
     */
    public static function webhookSecret(): string
    {
        return hash_hmac('sha256', 'telegram-webhook|'.config('services.telegram.bot_token'), (string) config('app.key'));
    }

    /** @param  array<string, mixed>  $params */
    public function call(string $method, array $params = []): Response
    {
        return Http::timeout(10)->asJson()
            ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/'.$method, $params);
    }

    public function sendMessage(string $chatId, string $html): Response
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $html,
            'parse_mode' => 'HTML',
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }
}
