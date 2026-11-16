<?php

namespace Tests\Feature;

use App\Ai\IncidentWriter;
use App\Enums\UserRole;
use App\Filament\Resources\Components\Pages\EditComponent;
use App\Filament\Resources\Components\RelationManagers\ProblemsRelationManager;
use App\Filament\Resources\Incidents\IncidentResource;
use App\Filament\Resources\Incidents\Pages\CreateIncident;
use App\Filament\Widgets\ActiveProblems;
use App\Models\Component;
use App\Models\User;
use App\Models\ZabbixProblem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeIncidentWriter;
use Tests\TestCase;

class MonitoringProblemsTest extends TestCase
{
    use RefreshDatabase;

    private Component $portal;

    protected function setUp(): void
    {
        parent::setUp();
        $this->portal = Component::create(['name' => 'Portal']);
        $this->problem($this->portal, 'High CPU on db-prod-03', 4);
        $this->problem($this->portal, 'Old disk alert on srv-x', 2, resolved: true);
    }

    private function problem(Component $c, string $name, int $severity, bool $resolved = false): ZabbixProblem
    {
        return $c->problems()->create([
            'eventid' => (string) random_int(1, 1_000_000),
            'name' => $name,
            'severity' => $severity,
            'started_at' => now()->subMinutes(10),
            'resolved_at' => $resolved ? now() : null,
        ]);
    }

    public function test_public_page_shows_problems_to_staff_only(): void
    {
        $this->get('/')->assertOk()->assertSee('Portal')->assertDontSee('db-prod-03');

        $this->actingAs(User::factory()->create(['role' => null]));
        $this->get('/')->assertOk()->assertDontSee('db-prod-03');

        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));
        $this->get('/')->assertOk()
            ->assertSee('High CPU on db-prod-03')
            ->assertDontSee('srv-x')
            ->assertSee('Draft incident');
        $this->get('/?view=compact')->assertOk()->assertDontSee('db-prod-03');
    }

    public function test_dashboard_widget_lists_active_problems_for_editors(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));

        $this->get('/admin')->assertOk();
        Livewire::test(ActiveProblems::class)
            ->assertSee('High CPU on db-prod-03')
            ->assertDontSee('srv-x');
    }

    public function test_component_edit_page_lists_problems(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));

        $this->get(route('filament.admin.resources.components.edit', $this->portal))->assertOk();
        Livewire::test(ProblemsRelationManager::class, ['ownerRecord' => $this->portal, 'pageClass' => EditComponent::class])
            ->assertSee('High CPU on db-prod-03')
            ->assertDontSee('srv-x');
    }

    public function test_create_form_prefills_components_and_shows_their_problems(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));
        $this->app->instance(IncidentWriter::class, new FakeIncidentWriter(enabled: false));

        Livewire::withQueryParams(['components' => (string) $this->portal->id])
            ->test(CreateIncident::class)
            ->assertFormSet(['component_ids' => [$this->portal->id]])
            ->assertSee('High CPU on db-prod-03');
    }

    public function test_draft_link_writes_a_hidden_draft_from_the_problems(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));
        $writer = new FakeIncidentWriter;
        $this->app->instance(IncidentWriter::class, $writer);

        $url = IncidentResource::createUrlFor([$this->portal->id], draft: true);
        $this->assertStringContainsString('draft=1', $url);

        Livewire::withQueryParams(['components' => (string) $this->portal->id, 'draft' => '1'])
            ->test(CreateIncident::class)
            ->assertFormSet(['title' => 'Portal is unavailable', 'visible' => false]);

        $this->assertCount(1, $writer->calls);
        $problems = $writer->calls[0][1]['components'][0]['monitoring_problems'];
        $this->assertSame(['High CPU on db-prod-03'], array_column($problems, 'name'));
    }

    public function test_draft_flag_is_dropped_without_ai(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Editor]));
        $this->app->instance(IncidentWriter::class, new FakeIncidentWriter(enabled: false));

        $this->assertStringNotContainsString('draft=', IncidentResource::createUrlFor([$this->portal->id], draft: true));
    }
}
