<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Mcp\Servers\StatusPageServer;
use App\Mcp\Tools\CancelMaintenance;
use App\Mcp\Tools\CreateIncident;
use App\Mcp\Tools\GetComponent;
use App\Mcp\Tools\GetIncident;
use App\Mcp\Tools\GetStatus;
use App\Mcp\Tools\ListComponents;
use App\Mcp\Tools\ListIncidents;
use App\Mcp\Tools\ListMaintenances;
use App\Mcp\Tools\PostIncidentUpdate;
use App\Mcp\Tools\ScheduleMaintenance;
use App\Mcp\Tools\SetComponentOverride;
use App\Mcp\Tools\UpdateIncident;
use App\Mcp\Tools\UpdateMaintenance;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Subscriber;
use App\Models\User;
use App\Notifications\StatusNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class McpServerTest extends TestCase
{
    use RefreshDatabase;

    private function user(UserRole $role = UserRole::Editor): User
    {
        return User::factory()->create(['role' => $role]);
    }

    // --- Read tools ---

    public function test_get_status_summarises_components_incidents_and_drafts(): void
    {
        $c = Component::create(['name' => 'Portal']);
        Component::create(['name' => 'Hidden', 'enabled' => false]);
        Incident::create(['title' => 'Draft', 'visible' => false, 'started_at' => now()])->components()->attach($c);

        StatusPageServer::actingAs($this->user())->tool(GetStatus::class)
            ->assertOk()
            ->assertStructuredContentPath('status', 'operational')
            ->assertStructuredContentCount(1, 'components')
            ->assertStructuredContentPath('open_incidents.0.title', 'Draft')
            ->assertStructuredContentPath('open_incidents.0.visible', false);
    }

    public function test_list_and_get_component(): void
    {
        $c = Component::create(['name' => 'Portal']);
        Component::create(['name' => 'Off', 'enabled' => false]);
        $c->problems()->create(['eventid' => '1', 'triggerid' => '1', 'name' => 'Disk full', 'severity' => 4, 'started_at' => now()]);

        $server = StatusPageServer::actingAs($this->user());
        $server->tool(ListComponents::class)->assertStructuredContentCount(1, 'data');
        $server->tool(ListComponents::class, ['include_disabled' => true])->assertStructuredContentCount(2, 'data');

        $server->tool(GetComponent::class, ['component_id' => $c->id, 'days' => 7])
            ->assertOk()
            ->assertStructuredContentPath('name', 'Portal')
            ->assertStructuredContentPath('active_problems.0.severity', 'High')
            ->assertStructuredContentPath('uptime.days', 7);

        $server->tool(GetComponent::class, ['component_id' => 999])->assertHasErrors();
    }

    public function test_list_and_get_incidents(): void
    {
        $open = Incident::create(['title' => 'Open', 'started_at' => now()]);
        $open->updates()->create(['status' => 'investigating', 'message' => 'Looking.']);
        Incident::create(['title' => 'Done', 'status' => IncidentStatus::Resolved, 'started_at' => now()->subDay()]);

        $server = StatusPageServer::actingAs($this->user());
        $server->tool(ListIncidents::class)->assertStructuredContentPath('meta.total', 2);
        $server->tool(ListIncidents::class, ['state' => 'open'])->assertStructuredContentCount(1, 'data');
        $server->tool(GetIncident::class, ['incident_id' => $open->id])
            ->assertStructuredContentPath('updates.0.message', 'Looking.');
    }

    public function test_list_maintenances(): void
    {
        Maintenance::create(['title' => 'Next', 'scheduled_start' => now()->addDay(), 'scheduled_end' => now()->addDays(2)]);
        Maintenance::create(['title' => 'Past', 'scheduled_start' => now()->subDays(3), 'scheduled_end' => now()->subDays(2)]);

        $server = StatusPageServer::actingAs($this->user());
        $server->tool(ListMaintenances::class)->assertStructuredContentCount(1, 'data');
        $server->tool(ListMaintenances::class, ['include_past' => true])->assertStructuredContentCount(2, 'data');
    }

    // --- Write tools ---

    public function test_incident_lifecycle(): void
    {
        Notification::fake();
        $subscriber = Subscriber::create(['email' => 's@example.test', 'verified_at' => now()]);
        $c = Component::create(['name' => 'Portal']);
        $editor = $this->user();
        $server = StatusPageServer::actingAs($editor);

        // Draft: no notification, but the component status applies.
        $id = $server->tool(CreateIncident::class, [
            'title' => 'Portal down', 'message' => 'Investigating.', 'visible' => false,
            'impact' => 'major', 'component_ids' => [$c->id], 'component_status' => 'major_outage',
        ])->assertOk()->assertStructuredContentPath('visible', false);

        $incident = Incident::query()->sole();
        $this->assertSame(ComponentStatus::MajorOutage, $c->fresh()->status);
        $this->assertSame($editor->id, $incident->updates()->first()->user_id);
        Notification::assertNothingSent();

        // Publish.
        $server->tool(UpdateIncident::class, ['incident_id' => $incident->id, 'visible' => true, 'component_status' => 'partial_outage'])
            ->assertOk()->assertStructuredContentPath('visible', true);
        $this->assertSame(ComponentStatus::PartialOutage, $c->fresh()->status);
        Notification::assertSentToTimes($subscriber, StatusNotification::class, 1);

        // Resolve.
        $server->tool(PostIncidentUpdate::class, ['incident_id' => $incident->id, 'status' => 'resolved', 'message' => 'Fixed.'])
            ->assertOk()->assertStructuredContentPath('status', 'resolved');
        $this->assertNotNull($incident->fresh()->resolved_at);
        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);
        Notification::assertSentToTimes($subscriber, StatusNotification::class, 2);
    }

    public function test_create_incident_validates_input(): void
    {
        StatusPageServer::actingAs($this->user())->tool(CreateIncident::class, [
            'title' => 'X', 'message' => 'Y', 'component_ids' => [999],
        ])->assertHasErrors();

        $this->assertSame(0, Incident::count());
    }

    public function test_removing_a_component_from_an_incident_restores_it(): void
    {
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);
        $server = StatusPageServer::actingAs($this->user());

        $server->tool(CreateIncident::class, [
            'title' => 'T', 'message' => 'M', 'visible' => false,
            'component_ids' => [$a->id, $b->id], 'component_status' => 'degraded',
        ]);
        $server->tool(UpdateIncident::class, ['incident_id' => Incident::query()->sole()->id, 'component_ids' => [$a->id]]);

        $this->assertSame(ComponentStatus::Degraded, $a->fresh()->status);
        $this->assertSame(ComponentStatus::Operational, $b->fresh()->status);
    }

    public function test_maintenance_schedule_update_and_cancel(): void
    {
        $c = Component::create(['name' => 'DB']);
        $server = StatusPageServer::actingAs($this->user());

        $server->tool(ScheduleMaintenance::class, [
            'title' => 'Upgrade', 'visible' => true, 'component_ids' => [$c->id],
            'scheduled_start' => now()->subMinute()->toIso8601String(),
            'scheduled_end' => now()->addHour()->toIso8601String(),
        ])->assertOk()->assertStructuredContentPath('status', 'in_progress');

        $m = Maintenance::query()->sole();
        $this->assertSame(ComponentStatus::Maintenance, $c->fresh()->status);

        $server->tool(UpdateMaintenance::class, ['maintenance_id' => $m->id, 'scheduled_end' => now()->subMinutes(2)->toIso8601String()])
            ->assertHasErrors(['The window must end after it starts.']);

        $server->tool(UpdateMaintenance::class, ['maintenance_id' => $m->id, 'scheduled_start' => now()->addDay()->toIso8601String(), 'scheduled_end' => now()->addDays(2)->toIso8601String()])
            ->assertOk()->assertStructuredContentPath('status', 'scheduled');
        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);

        $server->tool(CancelMaintenance::class, ['maintenance_id' => $m->id])->assertOk()->assertSee('deleted');
        $this->assertSame(0, Maintenance::count());
    }

    public function test_component_override_is_admin_only(): void
    {
        $c = Component::create(['name' => 'Portal']);

        StatusPageServer::actingAs($this->user(UserRole::Editor))
            ->tool(SetComponentOverride::class, ['component_id' => $c->id, 'status' => 'major_outage'])
            ->assertHasErrors();
        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);

        $admin = StatusPageServer::actingAs($this->user(UserRole::Admin));
        $admin->tool(SetComponentOverride::class, ['component_id' => $c->id, 'status' => 'major_outage', 'until' => now()->addHour()->toIso8601String()])
            ->assertOk()->assertStructuredContentPath('manual_override.status', 'major_outage');
        $this->assertSame(ComponentStatus::MajorOutage, $c->fresh()->status);

        $admin->tool(SetComponentOverride::class, ['component_id' => $c->id, 'status' => 'clear'])
            ->assertOk()->assertStructuredContentPath('manual_override', null);
        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);
    }

    public function test_override_tool_is_hidden_from_editors(): void
    {
        StatusPageServer::actingAs($this->user(UserRole::Editor))->tools()
            ->assertRegistered(CreateIncident::class)
            ->assertNotRegistered(SetComponentOverride::class);

        StatusPageServer::actingAs($this->user(UserRole::Admin))->tools()
            ->assertRegistered(SetComponentOverride::class);
    }

    // --- HTTP endpoint ---

    private function rpc(?string $token, string $method = 'tools/list', array $params = [])
    {
        return $this->withHeaders(array_filter([
            'Authorization' => $token ? "Bearer {$token}" : null,
            'Accept' => 'application/json, text/event-stream',
        ]))->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
    }

    public function test_endpoint_requires_a_staff_token(): void
    {
        $this->rpc(null)->assertUnauthorized();
        $this->rpc('garbage')->assertUnauthorized();

        $this->rpc($this->user()->createToken('mcp')->plainTextToken)
            ->assertOk()->assertJsonFragment(['name' => 'create-incident']);
    }

    public function test_token_command_issues_and_revokes(): void
    {
        $user = $this->user();

        $this->artisan('statuspage:mcp-token', ['email' => $user->email])->assertSuccessful();
        $this->assertSame(1, $user->tokens()->count());

        $this->artisan('statuspage:mcp-token', ['email' => $user->email, '--revoke' => true])->assertSuccessful();
        $this->assertSame(0, $user->tokens()->count());

        $this->artisan('statuspage:mcp-token', ['email' => 'nobody@example.test'])->assertFailed();
    }
}
