<?php

namespace Tests\Feature;

use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Component;
use App\Models\Setting;
use App\Models\User;
use App\Models\Webhook;
use App\Services\IncidentManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_are_recorded_with_user_and_diff(): void
    {
        $user = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($user);

        $incident = app(IncidentManager::class)->create(['title' => 'Slow', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Minor], [], 'Hmm.', $user);
        $incident->update(['impact' => IncidentImpact::Major]);

        $entry = AuditLog::query()->where('subject_type', 'Incident')->where('action', 'updated')->sole();
        $this->assertSame($user->id, $entry->user_id);
        $this->assertSame('panel', $entry->via);
        $this->assertSame(['minor', 'major'], $entry->changes['impact']);
        $this->assertSame('Slow', $entry->subject_label);
    }

    public function test_secrets_status_churn_and_runtime_settings_are_not_recorded(): void
    {
        Webhook::create(['name' => 'w', 'url' => 'https://hook.test', 'events' => [], 'secret' => 'shh']);
        $this->assertArrayNotHasKey('secret', AuditLog::query()->where('subject_type', 'Webhook')->sole()->changes);

        $c = Component::create(['name' => 'Portal']);
        $c->forceFill(['auto_status' => 'major_outage'])->saveQuietly();
        $c->refreshStatus();
        $this->assertSame(1, AuditLog::query()->where('subject_type', 'Component')->count()); // just "created"

        Setting::put('last_poll_at', now()->toIso8601String());
        Setting::put('site_name', 'Acme');
        $this->assertSame(['site_name'], AuditLog::query()->where('subject_type', 'Setting')->pluck('subject_label')->all());
    }

    public function test_system_changes_have_no_user(): void
    {
        Component::create(['name' => 'Portal']);

        $this->assertSame('system', AuditLog::query()->sole()->via);
    }
}
