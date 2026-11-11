<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Filament\Pages\ManageSettings;
use App\Filament\Resources\Components\Pages\CreateComponent;
use App\Filament\Resources\Components\Pages\EditComponent;
use App\Filament\Resources\Incidents\Pages\CreateIncident;
use App\Filament\Resources\Incidents\Pages\EditIncident;
use App\Filament\Resources\Incidents\RelationManagers\UpdatesRelationManager;
use App\Filament\Resources\Maintenances\Pages\CreateMaintenance;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class AdminFormsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]));
    }

    public function test_create_component_with_zabbix_source(): void
    {
        Livewire::test(CreateComponent::class)
            ->fillForm([
                'name' => 'Portal',
                'sources' => [[
                    'driver' => 'zabbix_host',
                    'config' => [
                        'hosts' => ['portal01', ' portal01 ', 'portal02'],
                        'host_groups' => [],
                        'tags' => [['tag' => 'service', 'value' => 'portal']],
                        'severity_map' => [0 => 'ignore', 1 => 'ignore', 2 => 'ignore', 3 => 'degraded', 4 => 'major_outage', 5 => 'major_outage'],
                    ],
                ]],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $config = Component::query()->where('name', 'Portal')->firstOrFail()->sources()->first()->config;
        $this->assertSame(['portal01', 'portal02'], $config['hosts']);
        $this->assertSame('ignore', $config['severity_map'][2]);
        $this->assertSame([['tag' => 'service', 'value' => 'portal']], $config['tags']);
    }

    public function test_editing_a_source_without_stored_severity_map_shows_defaults(): void
    {
        $c = Component::create(['name' => 'Legacy']);
        $c->sources()->create(['driver' => 'zabbix_host', 'config' => ['hosts' => ['a']]]);

        Livewire::test(EditComponent::class, ['record' => $c->id])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(config('statuspage.severity_map'), $c->sources()->first()->config['severity_map']);
    }

    public function test_create_incident_then_post_update(): void
    {
        $c = Component::create(['name' => 'Portal']);

        Livewire::test(CreateIncident::class)
            ->fillForm([
                'title' => 'Portal down',
                'status' => IncidentStatus::Investigating->value,
                'impact' => 'major',
                'component_ids' => [$c->id],
                'component_status' => ComponentStatus::MajorOutage->value,
                'message' => 'Investigating.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $incident = Incident::query()->firstOrFail();
        $this->assertSame(ComponentStatus::MajorOutage, $c->fresh()->status);
        $this->assertSame('Investigating.', $incident->updates()->first()->message);

        Livewire::test(EditIncident::class, ['record' => $incident->id])->assertFormSet(['component_ids' => [$c->id]]);

        Livewire::test(UpdatesRelationManager::class, ['ownerRecord' => $incident, 'pageClass' => EditIncident::class])
            ->callAction(TestAction::make(CreateAction::class)->table(), [
                'status' => IncidentStatus::Resolved->value,
                'message' => 'Fixed.',
            ])
            ->assertHasNoFormErrors();

        $this->assertSame(IncidentStatus::Resolved, $incident->fresh()->status);
        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);
    }

    public function test_create_maintenance_validates_window(): void
    {
        Livewire::test(CreateMaintenance::class)
            ->fillForm(['title' => 'Bad', 'scheduled_start' => now()->addDay(), 'scheduled_end' => now()])
            ->call('create')
            ->assertHasFormErrors(['scheduled_end']);
    }

    public function test_save_settings(): void
    {
        Livewire::test(ManageSettings::class)
            ->fillForm(['site_name' => 'Acme status', 'history_days' => 14, 'external_links' => [['name' => 'X', 'url' => 'https://x.test', 'description' => null]]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('Acme status', Setting::get('site_name'));
        $this->assertSame('https://x.test', Setting::get('external_links')[0]['url']);
    }
}
