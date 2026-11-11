<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\Maintenance;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WriteApiTest extends TestCase
{
    use RefreshDatabase;

    private function token(UserRole $role = UserRole::Editor): array
    {

        $user = User::factory()->create(['role' => $role]);

        return ['Authorization' => 'Bearer '.$user->createToken('ci')->plainTextToken];
    }

    public function test_requires_a_staff_token(): void
    {
        $this->postJson('/api/v1/incidents', ['title' => 'x', 'message' => 'y', 'visible' => false])->assertUnauthorized();
        $this->postJson('/api/v1/incidents', ['title' => 'x', 'message' => 'y', 'visible' => false], ['Authorization' => 'Bearer nope'])->assertUnauthorized();
    }

    public function test_incident_lifecycle(): void
    {
        $headers = $this->token();
        $c = Component::create(['name' => 'Portal']);

        $id = $this->postJson('/api/v1/incidents', [
            'title' => 'Portal slow', 'message' => 'Looking.', 'visible' => true, 'impact' => 'minor',
            'component_ids' => [$c->id], 'component_status' => 'degraded',
        ], $headers)->assertCreated()->assertJsonPath('data.title', 'Portal slow')->json('data.id');

        $this->assertSame(ComponentStatus::Degraded, $c->fresh()->status);

        $this->patchJson("/api/v1/incidents/{$id}", ['impact' => 'major'], $headers)->assertOk()->assertJsonPath('data.impact', 'major');

        $this->postJson("/api/v1/incidents/{$id}/updates", ['status' => 'monitoring', 'message' => 'Later.', 'publish_at' => now()->addHour()->toIso8601String()], $headers)
            ->assertStatus(202)->assertJsonStructure(['data' => ['scheduled_update_id', 'publish_at']]);

        $this->postJson("/api/v1/incidents/{$id}/updates", ['status' => 'resolved', 'message' => 'Fixed.'], $headers)
            ->assertCreated()->assertJsonPath('data.status', 'resolved');

        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);
        $this->assertSame('api', AuditLog::query()->where('subject_type', 'Incident')->where('action', 'updated')->first()->via);
    }

    public function test_create_from_template(): void
    {
        $c = Component::create(['name' => 'DB']);
        $t = IncidentTemplate::create(['name' => 'Failover', 'title' => 'Database failover', 'message' => 'Failing over.', 'impact' => 'major', 'component_ids' => [$c->id]]);

        $this->postJson('/api/v1/incidents', ['template_id' => $t->id, 'visible' => false], $this->token())
            ->assertCreated()->assertJsonPath('data.title', 'Database failover')->assertJsonPath('data.components.0.name', 'DB');

        $this->assertSame('Failing over.', Incident::query()->sole()->updates()->first()->message);
    }

    public function test_maintenance_crud(): void
    {
        $headers = $this->token();
        $c = Component::create(['name' => 'DB']);

        $id = $this->postJson('/api/v1/maintenances', [
            'title' => 'Upgrade', 'scheduled_start' => now()->addDay()->toIso8601String(), 'scheduled_end' => now()->addDay()->addHour()->toIso8601String(),
            'visible' => true, 'component_ids' => [$c->id], 'recurrence' => 'weekly',
        ], $headers)->assertCreated()->assertJsonPath('data.recurrence', 'weekly')->json('data.id');

        $this->patchJson("/api/v1/maintenances/{$id}", ['scheduled_end' => now()->toIso8601String()], $headers)->assertUnprocessable();
        $this->patchJson("/api/v1/maintenances/{$id}", ['title' => 'Upgrade v2'], $headers)->assertOk()->assertJsonPath('data.title', 'Upgrade v2');

        $this->deleteJson("/api/v1/maintenances/{$id}", [], $headers)->assertNoContent();
        $this->assertSame(0, Maintenance::query()->count());
    }

    public function test_overrides_are_admin_only(): void
    {
        $c = Component::create(['name' => 'Portal']);

        $this->putJson("/api/v1/components/{$c->id}/override", ['status' => 'major_outage'], $this->token())->assertForbidden();
        $this->assertNull($c->fresh()->manual_status);
    }

    public function test_admins_can_override(): void
    {
        $c = Component::create(['name' => 'Portal']);
        $admin = $this->token(UserRole::Admin);

        $this->putJson("/api/v1/components/{$c->id}/override", ['status' => 'major_outage'], $admin)
            ->assertOk()->assertJsonPath('data.status', 'major_outage');
        $this->putJson("/api/v1/components/{$c->id}/override", ['status' => 'clear'], $admin)
            ->assertOk()->assertJsonPath('data.status', 'operational');
    }
}
