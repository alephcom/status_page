<?php

namespace App\Console\Commands;

use App\Telegram\TelegramClient;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statuspage:telegram-setup {--remove : Remove the webhook instead}')]
#[Description('Register the Telegram bot webhook and its command menu')]
class TelegramSetup extends Command
{
    public function handle(TelegramClient $telegram): int
    {
        if (blank(config('services.telegram.bot_token'))) {
            $this->error('Set TELEGRAM_BOT_TOKEN first (from @BotFather).');

            return self::FAILURE;
        }

        $me = $telegram->call('getMe');
        if (! $me->json('ok')) {
            $this->error('Telegram rejected the token: '.$me->json('description', 'HTTP '.$me->status()));

            return self::FAILURE;
        }

        $username = $me->json('result.username');
        $this->info("Bot: @{$username}");

        if ($this->option('remove')) {
            $telegram->call('deleteWebhook');
            $this->info('Webhook removed.');

            return self::SUCCESS;
        }

        $url = route('telegram.webhook');
        if (! str_starts_with($url, 'https://')) {
            $this->error("Telegram only delivers to HTTPS URLs; APP_URL gives {$url}.");

            return self::FAILURE;
        }

        $result = $telegram->call('setWebhook', [
            'url' => $url,
            'secret_token' => TelegramClient::webhookSecret(),
            'allowed_updates' => ['message', 'my_chat_member'],
            'drop_pending_updates' => true,
        ]);

        if (! $result->json('ok')) {
            $this->error('setWebhook failed: '.$result->json('description', 'HTTP '.$result->status()));

            return self::FAILURE;
        }

        $telegram->call('setMyCommands', ['commands' => [
            ['command' => 'status', 'description' => 'Current status'],
            ['command' => 'start', 'description' => 'Subscribe to updates'],
            ['command' => 'stop', 'description' => 'Unsubscribe'],
            ['command' => 'help', 'description' => 'What this bot does'],
        ]]);

        $this->info("Webhook set to {$url}.");

        if (ltrim((string) config('services.telegram.bot_username'), '@') !== $username) {
            $this->warn("Set TELEGRAM_BOT_USERNAME={$username} in .env so the subscribe page links to the bot.");
        }

        return self::SUCCESS;
    }
}
