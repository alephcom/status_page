<?php

namespace App\Http\Controllers;

use App\Telegram\BotCommands;
use App\Telegram\TelegramClient;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class TelegramController extends Controller
{
    /** Updates from Telegram, authenticated by the secret token set in setWebhook. */
    public function webhook(Request $request, BotCommands $bot): Response
    {
        abort_unless(TelegramClient::enabled(), 404);
        abort_unless(hash_equals(TelegramClient::webhookSecret(), (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 403);

        try {
            $bot->handle($request->json()->all());
        } catch (Throwable $e) {
            // Always answer 200, or Telegram keeps redelivering the same update.
            report($e);
        }

        return response()->noContent(200);
    }

    /** "Subscribe on Telegram" from the subscribe form: carry the chosen components into /start. */
    public function subscribe(Request $request): RedirectResponse
    {
        abort_unless(TelegramClient::enabled(), 404);

        $data = $request->validate([
            'components' => ['array'],
            'components.*' => ['integer', Rule::exists('components', 'id')->where('visibility', 'public')],
        ]);

        return redirect()->away(TelegramClient::startLink(BotCommands::rememberSelection($data['components'] ?? [])));
    }
}
