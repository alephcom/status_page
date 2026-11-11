<?php

namespace Tests\Feature;

use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Filament\Resources\TelegramChats\Pages\CreateTelegramChat;
use App\Jobs\SendTelegramMessage;
use App\Models\Component;
use App\Models\TelegramChat;
use App\Models\User;
use App\Notifications\StatusNotification;
use App\Services\IncidentManager;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class TelegramTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.telegram.bot_token' => '123:abc', 'services.telegram.bot_username' => 'ExampleStatusBot']);
        $this->respondWith(['ok' => true, 'result' => ['chat' => ['type' => 'channel', 'title' => 'Ops'], 'username' => 'ExampleStatusBot']]);
        Http::fake(['api.telegram.org/*' => fn () => Http::response(...$this->telegramResponse)]);
    }

    /** @var array{0: array<string, mixed>, 1: int} */
    private array $telegramResponse;

    private function respondWith(array $body, int $status = 200): void
    {
        $this->telegramResponse = [$body, $status];
    }

    /** @param  array<string, mixed>  $update */
    private function update(array $update, ?string $secret = null)
    {
        return $this->postJson('/telegram/webhook', $update, ['X-Telegram-Bot-Api-Secret-Token' => $secret ?? TelegramClient::webhookSecret()]);
    }

    private function message(string $text, int $chatId = 42, string $type = 'private'): array
    {
        return ['update_id' => 1, 'message' => ['message_id' => 1, 'text' => $text,
            'chat' => ['id' => $chatId, 'type' => $type, 'first_name' => 'Pat', 'title' => $type === 'private' ? null : 'Team']]];
    }

    private function lastReply(): string
    {
        $sent = collect(Http::recorded())->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $r) => str_ends_with($r->url(), '/sendMessage'))->last();

        return $sent['text'];
    }

    public function test_webhook_rejects_wrong_secret_and_404s_when_disabled(): void
    {
        $this->update($this->message('/start'), 'wrong')->assertForbidden();
        $this->assertSame(0, TelegramChat::count());

        config(['services.telegram.bot_token' => null]);
        $this->update($this->message('/start'))->assertNotFound();
    }

    public function test_start_and_stop_in_a_private_chat(): void
    {
        $this->update($this->message('/start'))->assertOk();

        $chat = TelegramChat::query()->sole();
        $this->assertSame('42', $chat->chat_id);
        $this->assertSame('Pat', $chat->title);
        $this->assertFalse($chat->added_by_admin);
        $this->assertStringContainsString('subscribed', $this->lastReply());
        $this->assertStringContainsString('all components', $this->lastReply());

        $this->update($this->message('/start'))->assertOk();
        $this->assertSame(1, TelegramChat::count());
        $this->assertStringContainsString('already subscribed', $this->lastReply());

        $this->update($this->message('/stop'))->assertOk();
        $this->assertSame(0, TelegramChat::count());
    }

    public function test_subscribe_page_carries_component_choice_into_start(): void
    {
        $a = Component::create(['name' => 'API']);
        Component::create(['name' => 'Web']);

        $this->get('/subscribe')->assertSee('Get updates on Telegram');

        $location = $this->post('/subscribe/telegram', ['components' => [$a->id]])->headers->get('Location');
        $this->assertStringStartsWith('https://t.me/ExampleStatusBot?start=', $location);
        $payload = substr($location, strpos($location, '=') + 1);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]{1,64}$/', $payload);

        $this->update($this->message("/start {$payload}"));
        $this->assertSame([$a->id], TelegramChat::query()->sole()->components()->pluck('components.id')->all());
        $this->assertStringContainsString('API', $this->lastReply());

        // The payload works once.
        $this->update($this->message('/stop'));
        $this->update($this->message("/start {$payload}"));
        $this->assertSame(0, TelegramChat::query()->sole()->components()->count());
    }

    public function test_group_commands_with_bot_suffix_and_removal(): void
    {
        $this->update($this->message('/start@ExampleStatusBot', -100123, 'supergroup'))->assertOk();
        $this->assertSame('Team', TelegramChat::query()->sole()->title);

        // Unknown commands in groups are ignored.
        $before = count(Http::recorded());
        $this->update($this->message('/deploy', -100123, 'supergroup'))->assertOk();
        $this->assertCount($before, Http::recorded());

        $this->update(['update_id' => 2, 'my_chat_member' => ['chat' => ['id' => -100123], 'new_chat_member' => ['status' => 'kicked']]])->assertOk();
        $this->assertSame(0, TelegramChat::count());
    }

    public function test_status_command(): void
    {
        Component::create(['name' => 'API', 'manual_status' => 'major_outage']);
        Component::create(['name' => 'Web']);

        $this->update($this->message('/status'))->assertOk();

        $reply = $this->lastReply();
        $this->assertStringContainsString('Major outage', $reply);
        $this->assertStringContainsString('API', $reply);
        $this->assertStringNotContainsString('Web', $reply);
    }

    public function test_incidents_are_sent_to_interested_chats(): void
    {
        Queue::fake();
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);
        $all = TelegramChat::create(['chat_id' => '1']);
        $onlyB = TelegramChat::create(['chat_id' => '2']);
        $onlyB->components()->attach($b);

        app(IncidentManager::class)->create(['title' => 'A <broke>', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor], [$a->id], 'Looking **now**.');

        Queue::assertPushed(SendTelegramMessage::class, 1);
        Queue::assertPushed(SendTelegramMessage::class, fn ($job) => $job->chat->is($all)
            && str_contains($job->html, 'A &lt;broke&gt;') && str_contains($job->html, '<b>now</b>'));
    }

    public function test_nothing_is_queued_when_telegram_is_disabled(): void
    {
        Queue::fake();
        config(['services.telegram.bot_token' => null]);
        TelegramChat::create(['chat_id' => '1']);

        app(IncidentManager::class)->create(['title' => 'X', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor], [], 'Y');

        Queue::assertNotPushed(SendTelegramMessage::class);
    }

    public function test_blocked_chats_are_dropped_but_admin_chats_keep_the_error(): void
    {
        $this->respondWith(['ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user'], 403);
        $subscriber = TelegramChat::create(['chat_id' => '1']);
        $channel = TelegramChat::create(['chat_id' => '@ops', 'added_by_admin' => true]);

        (new SendTelegramMessage($subscriber, 'hi'))->handle(app(TelegramClient::class));
        (new SendTelegramMessage($channel, 'hi'))->handle(app(TelegramClient::class));

        $this->assertModelMissing($subscriber);
        $this->assertSame('Forbidden: bot was blocked by the user', $channel->fresh()->last_error);
    }

    public function test_group_migration_updates_the_chat_id(): void
    {
        $this->respondWith(['ok' => false, 'description' => 'Bad Request: group chat was upgraded to a supergroup chat', 'parameters' => ['migrate_to_chat_id' => -1009]], 400);
        $chat = TelegramChat::create(['chat_id' => '-5']);

        $job = (new SendTelegramMessage($chat, 'hi'))->withFakeQueueInteractions();
        $job->handle(app(TelegramClient::class));

        $this->assertSame('-1009', $chat->fresh()->chat_id);
        $job->assertReleased();
    }

    public function test_message_formatting_escapes_html(): void
    {
        $html = TelegramMessage::fromNotification(new StatusNotification('Incident: <x>', 'h', ['**Status:** a & b'], 'View', 'https://example.test/i/1?a=1&b=2'));

        $this->assertStringContainsString('<b>Incident: &lt;x&gt;</b>', $html);
        $this->assertStringContainsString('<b>Status:</b> a &amp; b', $html);
        $this->assertStringContainsString('href="https://example.test/i/1?a=1&amp;b=2"', $html);
    }

    public function test_admin_adds_a_channel_and_a_test_message_is_sent(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        Livewire::test(CreateTelegramChat::class)
            ->fillForm(['chat_id' => '@ops_status'])
            ->call('create')
            ->assertHasNoFormErrors();

        $chat = TelegramChat::query()->sole();
        $this->assertTrue($chat->added_by_admin);
        $this->assertSame('Ops', $chat->title);
        $this->assertSame('channel', $chat->type);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/sendMessage') && $r['chat_id'] === '@ops_status');
    }

    public function test_editors_cannot_manage_telegram_chats(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]))->get('/admin/telegram-chats')->assertForbidden();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/admin/telegram-chats')->assertOk();
    }

    public function test_setup_command_registers_the_webhook(): void
    {
        config(['app.url' => 'https://status.example.test']);
        $this->app['url']->forceRootUrl('https://status.example.test');
        $this->app['url']->forceScheme('https');

        $this->artisan('statuspage:telegram-setup')->assertSuccessful();

        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/setWebhook')
            && $r['url'] === 'https://status.example.test/telegram/webhook'
            && $r['secret_token'] === TelegramClient::webhookSecret());
    }
}
