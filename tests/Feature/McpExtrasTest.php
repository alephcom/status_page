<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mcp\Servers\StatusPageServer;
use App\Mcp\Tools\CreateIncident;
use App\Mcp\Tools\ListIncidentTemplates;
use App\Mcp\Tools\PostIncidentUpdate;
use App\Mcp\Tools\ScheduleMaintenance;
use App\Mcp\Tools\UpdateIncident;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpExtrasTest extends TestCase
{
    use RefreshDatabase;

    private function editor(): User
    {
        return User::factory()->create(['role' => UserRole::Editor]);
    }

    public function test_templates(): void
    {
        $c = Component::create(['name' => 'DB']);
        $t = IncidentTemplate::create(['name' => 'Failover', 'title' => 'Database failover', 'message' => 'Failing over.', 'impact' => 'major', 'component_ids' => [$c->id]]);

        StatusPageServer::actingAs($this->editor())->tool(ListIncidentTemplates::class)
            ->assertOk()->assertStructuredContentPath('data.0.name', 'Failover');

        StatusPageServer::actingAs($this->editor())->tool(CreateIncident::class, ['template_id' => $t->id, 'visible' => false])
            ->assertOk()
            ->assertStructuredContentPath('title', 'Database failover')
            ->assertStructuredContentPath('impact', 'major')
            ->assertStructuredContentPath('components.0.id', $c->id);
    }

    public function test_scheduled_update_and_postmortem(): void
    {
        $incident = Incident::create(['title' => 'Down', 'started_at' => now()]);
        $incident->updates()->create(['status' => 'investigating', 'message' => 'x']);

        StatusPageServer::actingAs($this->editor())->tool(PostIncidentUpdate::class, [
            'incident_id' => $incident->id, 'status' => 'monitoring', 'message' => 'Later.', 'publish_at' => now()->addHour()->toIso8601String(),
        ])->assertOk()->assertStructuredContentPath('incident.scheduled_updates.0.status', 'monitoring');

        $this->assertSame(1, $incident->scheduledUpdates()->count());

        StatusPageServer::actingAs($this->editor())->tool(UpdateIncident::class, [
            'incident_id' => $incident->id, 'postmortem' => '### Summary', 'postmortem_published' => true,
        ])->assertOk()->assertStructuredContentPath('postmortem_published', true);
    }

    public function test_recurring_maintenance(): void
    {
        $c = Component::create(['name' => 'DB']);

        StatusPageServer::actingAs($this->editor())->tool(ScheduleMaintenance::class, [
            'title' => 'Weekly patching', 'scheduled_start' => now()->addDay()->toIso8601String(), 'scheduled_end' => now()->addDay()->addHour()->toIso8601String(),
            'visible' => true, 'component_ids' => [$c->id], 'recurrence' => 'weekly',
        ])->assertOk()->assertStructuredContentPath('recurrence', 'weekly');
    }
}
