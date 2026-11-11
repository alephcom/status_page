<?php

namespace Tests\Feature;

use App\Ai\IncidentWriter;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ManageSettings;
use App\Filament\Pages\SlaReportPage;
use App\Filament\Resources\Components\Pages\EditComponent;
use App\Filament\Resources\Incidents\Pages\CreateIncident;
use App\Filament\Resources\Incidents\Pages\EditIncident;
use App\Filament\Resources\Incidents\RelationManagers\UpdatesRelationManager;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\Setting;
use App\Models\User;
use App\Services\IncidentManager;
use Database\Seeders\DemoSeeder;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Fakes\FakeIncidentWriter;
use Tests\TestCase;

class AdminExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($this->admin);
    }

    public function test_new_pages_render(): void
    {
        $this->seed(DemoSeeder::class);
        IncidentTemplate::create(['name' => 'T', 'title' => 'T', 'message' => 'M']);

        foreach (['/admin/incident-templates', '/admin/incident-templates/create', '/admin/incident-templates/1/edit',
            '/admin/audit-logs', '/admin/sla-report', '/admin/sla-report/print?month=2026-10', '/admin/incidents/create'] as $url) {
            $this->get($url)->assertOk();
        }

        $editor = User::factory()->create(['role' => UserRole::Editor]);
        $this->actingAs($editor)->get('/admin/audit-logs')->assertForbidden();
        $this->actingAs($editor)->get('/admin/incident-templates')->assertOk();
    }

    public function test_settings_save_and_validate_new_fields(): void
    {
        Livewire::test(ManageSettings::class)
            ->fillForm([
                'maintenance_reminder_minutes' => 30,
                'stale_alert_minutes' => 5,
                'enabled_locales' => ['en', 'de'],
                'default_locale' => 'de',
                'private_ip_ranges' => ['10.0.0.0/8', '192.168.1.5'],
                'announcement' => 'Hello',
                'brand_color' => '#ff0000',
            ])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(30, (int) Setting::get('maintenance_reminder_minutes'));
        $this->assertSame(['en', 'de'], Setting::get('enabled_locales'));

        Livewire::test(ManageSettings::class)
            ->fillForm(['private_ip_ranges' => ['not-an-ip']])
            ->call('save')
            ->assertHasErrors('data.private_ip_ranges');

        Livewire::test(ManageSettings::class)
            ->fillForm(['enabled_locales' => ['en'], 'default_locale' => 'fr'])
            ->call('save')
            ->assertHasErrors('data.default_locale');
    }

    public function test_template_prefills_create_form(): void
    {
        $c = Component::create(['name' => 'DB']);
        $t = IncidentTemplate::create(['name' => 'Failover', 'title' => 'Database failover', 'message' => 'Failing over.', 'impact' => 'major', 'component_ids' => [$c->id]]);

        Livewire::test(CreateIncident::class)
            ->fillForm(['template' => $t->id])
            ->assertFormSet(['title' => 'Database failover', 'message' => 'Failing over.', 'component_ids' => [$c->id]])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertSame(IncidentImpact::Major, Incident::query()->sole()->impact);
    }

    public function test_ai_draft_fills_the_create_form(): void
    {
        $this->app->instance(IncidentWriter::class, new FakeIncidentWriter);

        Livewire::test(CreateIncident::class)
            ->callAction(TestAction::make('draftWithAi')->schemaComponent('message'))
            ->assertFormSet(['title' => 'Portal is unavailable', 'message' => 'Some users cannot sign in. We are looking into it.']);
    }

    public function test_ai_actions_are_hidden_without_a_key(): void
    {
        $this->app->instance(IncidentWriter::class, new FakeIncidentWriter(enabled: false));

        Livewire::test(CreateIncident::class)
            ->assertActionDoesNotExist(TestAction::make('draftWithAi')->schemaComponent('message'));
    }

    public function test_scheduled_update_from_timeline_and_postmortem_publishing(): void
    {
        $incident = app(IncidentManager::class)->create(['title' => 'Down', 'status' => IncidentStatus::Investigating, 'impact' => IncidentImpact::Major], [], 'x');

        Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => EditIncident::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'status' => IncidentStatus::Monitoring->value,
                'message' => 'Later.',
                'publish_at' => now()->addHour()->format('Y-m-d H:i:s'),
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(1, $incident->scheduledUpdates()->count());
        $this->assertCount(1, $incident->fresh()->updates);

        app(IncidentManager::class)->postUpdate($incident, IncidentStatus::Resolved, 'Fixed.');

        Livewire::test(EditIncident::class, ['record' => $incident->id])
            ->fillForm(['postmortem' => '### Summary', 'postmortem_published' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($incident->fresh()->hasPublishedPostmortem());
    }

    public function test_dependency_cycles_are_rejected(): void
    {
        $a = Component::create(['name' => 'A']);
        $b = Component::create(['name' => 'B']);
        $b->dependencies()->attach($a);

        Livewire::test(EditComponent::class, ['record' => $a->id])
            ->fillForm(['dependencies' => [$b->id]])
            ->call('save')
            ->assertHasFormErrors(['dependencies']);
    }

    public function test_sla_page_lists_components(): void
    {
        Component::create(['name' => 'Portal', 'sla_target' => 99.5]);

        Livewire::test(SlaReportPage::class)->assertSee('Portal')->assertSee('99.5 %');
    }
}
