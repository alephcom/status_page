<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\Recurrence;
use App\Enums\WebhookEvent;
use App\Jobs\SendWebhook;
use App\Models\Component;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\Webhook;
use App\Notifications\StatusNotification;
use App\Services\MaintenanceManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MaintenanceLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function schedule(Component $c, string $start, string $end, array $extra = []): Maintenance
    {
        $m = Maintenance::create(['title' => 'DB upgrade', 'scheduled_start' => now()->modify($start), 'scheduled_end' => now()->modify($end)] + $extra);
        $m->components()->attach($c);
        app(MaintenanceManager::class)->saved($m);

        return $m->fresh();
    }

    public function test_reminder_start_and_end_are_each_sent_once(): void
    {
        Notification::fake();
        Bus::fake([SendWebhook::class]);
        $c = Component::create(['name' => 'DB']);
        $sub = Subscriber::create(['email' => 's@example.test', 'verified_at' => now()]);
        Webhook::create(['name' => 'w', 'url' => 'https://hook.test', 'events' => [WebhookEvent::MaintenanceStarted->value, WebhookEvent::MaintenanceCompleted->value]]);

        $m = $this->schedule($c, '+3 hours', '+4 hours');
        Notification::assertSentToTimes($sub, StatusNotification::class, 1); // "scheduled"

        $manager = app(MaintenanceManager::class);

        $this->travel(125)->minutes(); // 55 minutes before the start
        $manager->tick();
        $manager->tick();
        Notification::assertSentToTimes($sub, StatusNotification::class, 2);
        $this->assertNotNull($m->fresh()->reminder_sent_at);

        $this->travel(1)->hours();
        $manager->tick();
        $manager->tick();
        $this->assertSame(ComponentStatus::Maintenance, $c->fresh()->status);
        Notification::assertSentToTimes($sub, StatusNotification::class, 3);
        Bus::assertDispatched(SendWebhook::class, fn ($job) => $job->event === 'maintenance.started');

        $this->travel(1)->hours();
        $manager->tick();
        $manager->tick();
        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);
        Notification::assertSentToTimes($sub, StatusNotification::class, 4);
        Bus::assertDispatched(SendWebhook::class, fn ($job) => $job->event === 'maintenance.completed');
        Bus::assertDispatchedTimes(SendWebhook::class, 2);
    }

    public function test_no_separate_reminder_when_scheduled_inside_the_reminder_window(): void
    {
        Notification::fake();
        $c = Component::create(['name' => 'DB']);
        $sub = Subscriber::create(['email' => 's@example.test', 'verified_at' => now()]);

        $this->schedule($c, '+30 minutes', '+2 hours');
        app(MaintenanceManager::class)->tick();

        Notification::assertSentToTimes($sub, StatusNotification::class, 1);
    }

    public function test_lifecycle_emails_can_be_turned_off_but_webhooks_still_fire(): void
    {
        Notification::fake();
        Bus::fake([SendWebhook::class]);
        Setting::putMany(['maintenance_reminder_minutes' => 0, 'notify_maintenance_lifecycle' => false]);
        $c = Component::create(['name' => 'DB']);
        $sub = Subscriber::create(['email' => 's@example.test', 'verified_at' => now()]);
        Webhook::create(['name' => 'w', 'url' => 'https://hook.test', 'events' => [WebhookEvent::MaintenanceStarted->value]]);

        $this->schedule($c, '+10 minutes', '+1 hour');
        $this->travel(15)->minutes();
        app(MaintenanceManager::class)->tick();

        Notification::assertSentToTimes($sub, StatusNotification::class, 1);
        Bus::assertDispatched(SendWebhook::class, fn ($job) => $job->event === 'maintenance.started');
    }

    public function test_rescheduling_into_the_future_rearms_notifications(): void
    {
        $c = Component::create(['name' => 'DB']);
        $m = $this->schedule($c, '+3 hours', '+4 hours');
        $m->forceFill(['reminder_sent_at' => now()])->save();

        $m->update(['scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDay()->addHour()]);

        $this->assertNull($m->fresh()->reminder_sent_at);
    }

    public function test_recurring_window_rolls_on_to_the_next_occurrence(): void
    {
        Notification::fake();
        $c = Component::create(['name' => 'DB']);
        $sub = Subscriber::create(['email' => 's@example.test', 'verified_at' => now()]);
        $m = $this->schedule($c, '+1 hour', '+2 hours', ['recurrence' => Recurrence::Weekly, 'recurrence_until' => now()->addWeeks(2)->addDay()]);

        $this->travel(3)->hours();
        app(MaintenanceManager::class)->tick();

        $next = Maintenance::query()->whereKeyNot($m->id)->sole();
        $this->assertNull($m->fresh()->recurrence);
        $this->assertSame(Recurrence::Weekly, $next->recurrence);
        $this->assertTrue($next->scheduled_start->equalTo($m->scheduled_start->copy()->addWeek()));
        $this->assertEquals([$c->id], $next->components()->pluck('components.id')->all());
        $this->assertTrue($next->notified);

        // Only the original "scheduled" message; occurrences get reminders instead.
        Notification::assertSentToTimes($sub, StatusNotification::class, 1);

        // Third occurrence would start after recurrence_until + nothing more.
        $this->travelTo($next->scheduled_end->copy()->addMinute());
        app(MaintenanceManager::class)->tick();
        $third = Maintenance::query()->latest('id')->first();
        $this->travelTo($third->scheduled_end->copy()->addMinute());
        app(MaintenanceManager::class)->tick();
        $this->assertSame(3, Maintenance::query()->count());
    }

    public function test_tick_command_runs(): void
    {
        $this->artisan('statuspage:tick')->assertSuccessful();
    }
}
