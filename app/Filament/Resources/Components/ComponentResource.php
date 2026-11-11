<?php

namespace App\Filament\Resources\Components;

use App\Enums\ComponentStatus;
use App\Enums\ComponentVisibility;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Support\ZabbixLookup;
use App\Models\Component;
use App\Services\MetricPoller;
use App\StatusSources\StatusSourceManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Throwable;
use UnitEnum;

class ComponentResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Component::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = 'Components';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        $severityLabels = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];
        $statusOptions = ['ignore' => 'Ignore'] + collect(ComponentStatus::cases())
            ->reject(fn ($s) => $s === ComponentStatus::Operational || $s === ComponentStatus::Maintenance)
            ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()])->all();
        $defaults = config('statuspage.severity_map');

        return $schema->components([
            Section::make('Details')->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255),
                Select::make('component_group_id')->label('Group')
                    ->relationship('group', 'name')
                    ->createOptionForm([TextInput::make('name')->required()])
                    ->placeholder('No group'),
                Textarea::make('description')->rows(2)->columnSpanFull()
                    ->helperText('Shown as a tooltip on the public page.'),
                TextInput::make('link')->url()->maxLength(255),
                TextInput::make('order')->numeric()->default(0),
                Select::make('visibility')->options(ComponentVisibility::class)->default(ComponentVisibility::Public)
                    ->required()->selectablePlaceholder(false)
                    ->helperText('Private components are shown to signed-in staff and the networks set under Settings → Status page.'),
                TextInput::make('sla_target')->label('SLA target')->numeric()->minValue(0)->maxValue(100)->step(0.001)->suffix('%')
                    ->helperText('Optional, e.g. 99.9. Used by the SLA report.'),
                Select::make('dependencies')->label('Depends on')
                    ->relationship('dependencies', 'name', fn ($query, ?Component $record) => $query->when($record, fn ($q) => $q->whereKeyNot($record->id)))
                    ->multiple()->preload()
                    ->rules([fn (?Component $record) => function (string $attribute, $value, \Closure $fail) use ($record) {
                        foreach ((array) $value as $id) {
                            if ($record && $record->wouldCycle((int) $id)) {
                                $fail('That would make components depend on each other in a loop.');
                            }
                        }
                    }])
                    ->helperText('If one of these is down or degraded, this component shows it too.')
                    ->columnSpanFull(),
                Toggle::make('enabled')->default(true)->helperText('Disabled components are hidden and not polled.'),
            ]),

            Section::make('Monitoring')
                ->description('The component takes the worst status of its sources. Without sources its status is set by hand, incidents and maintenance only.')
                ->schema([
                    Repeater::make('sources')
                        ->relationship()
                        ->hiddenLabel()
                        ->addActionLabel('Add source')
                        ->defaultItems(0)
                        ->collapsible()
                        ->itemLabel(fn (array $state) => self::sourceSummary($state))
                        ->mutateRelationshipDataBeforeFillUsing(fn (array $data) => self::normaliseSource($data))
                        ->mutateRelationshipDataBeforeCreateUsing(fn (array $data) => self::normaliseSource($data))
                        ->mutateRelationshipDataBeforeSaveUsing(fn (array $data) => self::normaliseSource($data))
                        ->schema([
                            Select::make('driver')
                                ->options(fn () => app(StatusSourceManager::class)->drivers())
                                ->default('zabbix_host')
                                ->required()
                                ->live(),
                            Select::make('config.hosts')->label('Zabbix hosts')
                                ->multiple()
                                ->searchable()
                                ->searchDebounce(400)
                                ->searchPrompt('Type at least 2 characters to search Zabbix')
                                ->getSearchResultsUsing(fn (?string $search) => ZabbixLookup::hosts($search))
                                ->getOptionLabelsUsing(fn (array $values) => ZabbixLookup::labels($values))
                                ->helperText('Searches host and visible names in Zabbix.')
                                ->visible(fn (Get $get) => $get('driver') === 'zabbix_host'),
                            Select::make('config.host_groups')->label('Zabbix host groups')
                                ->multiple()
                                ->searchable()
                                ->searchDebounce(400)
                                ->searchPrompt('Type at least 2 characters to search Zabbix')
                                ->getSearchResultsUsing(fn (?string $search) => ZabbixLookup::hostGroups($search))
                                ->getOptionLabelsUsing(fn (array $values) => ZabbixLookup::labels($values))
                                ->visible(fn (Get $get) => $get('driver') === 'zabbix_host'),
                            TagsInput::make('config.services')->label('Zabbix services')
                                ->placeholder('service name, then Enter')
                                ->helperText('Services from Services → Services in Zabbix. Their status is the severity of the worst problem affecting them.')
                                ->visible(fn (Get $get) => $get('driver') === 'zabbix_service'),
                            Repeater::make('config.tags')->label('Extra required trigger tags')
                                ->visible(fn (Get $get) => $get('driver') === 'zabbix_host')
                                ->helperText('On top of the global public tags ('.config('statuspage.zabbix.public_tags').'). Leave value empty to match any value.')
                                ->schema([
                                    TextInput::make('tag')->required(),
                                    TextInput::make('value'),
                                ])
                                ->columns(2)
                                ->defaultItems(0)
                                ->addActionLabel('Add tag'),
                            Fieldset::make('Severity → status')->columns(3)->schema(
                                collect(range(0, 5))->map(fn (int $i) => Select::make("config.severity_map.{$i}")
                                    ->label($severityLabels[$i])
                                    ->options($statusOptions)
                                    ->default($defaults[$i])
                                    ->selectablePlaceholder(false))->all(),
                            ),
                        ]),
                ]),

            Section::make('Graphs')
                ->description('Show recent values of Zabbix items under the component, e.g. a web scenario\'s response time. Only numeric items.')
                ->collapsed(fn (?Component $record) => ! $record?->metrics()->exists())
                ->schema([
                    Repeater::make('metrics')
                        ->relationship()
                        ->hiddenLabel()
                        ->addActionLabel('Add graph')
                        ->defaultItems(0)
                        ->orderColumn('order')
                        ->collapsible()
                        ->itemLabel(fn (array $state) => $state['name'] ?? null)
                        ->columns(3)
                        ->schema([
                            TextInput::make('name')->required()->maxLength(100)->placeholder('Response time'),
                            TextInput::make('host')->label('Zabbix host')->required()->maxLength(255),
                            TextInput::make('item_key')->label('Item key')->required()->maxLength(255)
                                ->placeholder('web.test.time[Portal,Home,resp]'),
                            TextInput::make('unit')->maxLength(20)->helperText('Empty uses the item\'s unit.'),
                            TextInput::make('decimals')->numeric()->minValue(0)->maxValue(6)->default(0),
                            Toggle::make('enabled')->default(true)->inline(false),
                        ]),
                ]),

            Section::make('Manual override')
                ->description('Force a status regardless of monitoring, e.g. while monitoring is wrong.')
                ->columns(2)
                ->collapsed(fn (?Component $record) => ! $record?->manual_status)
                ->schema([
                    Select::make('manual_status')->label('Status')
                        ->options(ComponentStatus::class)
                        ->placeholder('Automatic'),
                    DateTimePicker::make('manual_status_until')->label('Until')
                        ->helperText('Empty means until cleared.'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('order')
            ->reorderable('order')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('group.name')->label('Group')->sortable(),
                TextColumn::make('status')->badge(),
                TextColumn::make('auto_status')->label('Monitoring')->badge()->toggleable(),
                TextColumn::make('active_problems_count')->counts('activeProblems')->label('Problems'),
                TextColumn::make('sources_count')->counts('sources')->label('Sources')->toggleable(),
                TextColumn::make('visibility')->badge()->color(fn ($state) => $state === ComponentVisibility::Private ? 'warning' : 'gray')
                    ->formatStateUsing(fn ($state) => $state === ComponentVisibility::Private ? 'Private' : 'Public')->toggleable(),
                ToggleColumn::make('enabled'),
            ])
            ->filters([
                SelectFilter::make('status')->options(ComponentStatus::class),
                SelectFilter::make('component_group_id')->label('Group')->relationship('group', 'name'),
            ])
            ->recordActions([
                self::testAction(),
                self::testMetricsAction(),
                EditAction::make(),
            ]);
    }

    /** Fetch the graphs' data now and say what went wrong, if anything. */
    public static function testMetricsAction(): Action
    {
        return Action::make('testMetrics')
            ->label('Fetch graphs')
            ->icon(Heroicon::OutlinedChartBar)
            ->color('gray')
            ->visible(fn (Component $record) => $record->metrics()->exists())
            ->action(function (Component $record) {
                $poller = app(MetricPoller::class);
                $lines = [];

                foreach ($record->metrics as $metric) {
                    try {
                        $poller->pollOne($metric);
                        $lines[] = "**{$metric->name}**: ok, ".$metric->points()->count().' samples';
                    } catch (Throwable $e) {
                        $metric->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 255)])->saveQuietly();
                        $lines[] = "**{$metric->name}**: {$e->getMessage()}";
                    }
                }

                Notification::make()->title('Graphs')->body(implode("\n\n", $lines))->persistent()->send();
            });
    }

    /** Ask the sources right now and show what they report, without saving. */
    public static function testAction(): Action
    {
        return Action::make('test')
            ->label('Test sources')
            ->icon(Heroicon::OutlinedSignal)
            ->color('gray')
            ->visible(fn (Component $record) => $record->sources()->exists())
            ->action(function (Component $record) {
                $manager = app(StatusSourceManager::class);
                $lines = [];

                try {
                    foreach ($record->sources->groupBy('driver') as $driver => $sources) {
                        foreach ($manager->driver($driver)->resolve($sources) as $result) {
                            $lines[] = "**{$result->status->getLabel()}**";
                            foreach ($result->problems as $p) {
                                $lines[] = "• {$p->name} (severity {$p->severity}) → {$p->status->getLabel()}";
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Notification::make()->danger()->title('Source failed')->body($e->getMessage())->persistent()->send();

                    return;
                }

                Notification::make()->success()->title('Sources answered')
                    ->body(implode("\n\n", $lines ?: ['No matching problems.']))->persistent()->send();
            });
    }

    /** @param array<string, mixed> $data */
    private static function normaliseSource(array $data): array
    {
        $data['config'] = app(StatusSourceManager::class)->driver($data['driver'])->normaliseConfig($data['config'] ?? []);

        return $data;
    }

    /** @param array<string, mixed> $state */
    private static function sourceSummary(array $state): string
    {
        $config = $state['config'] ?? [];
        $parts = array_filter([
            ($config['hosts'] ?? []) ? 'hosts: '.implode(', ', $config['hosts']) : null,
            ($config['host_groups'] ?? []) ? 'groups: '.implode(', ', $config['host_groups']) : null,
        ]);

        if (($state['driver'] ?? null) === 'zabbix_service') {
            $parts = ($config['services'] ?? []) ? ['services: '.implode(', ', $config['services'])] : [];
        }

        return ($state['driver'] ?? 'source').($parts ? ' – '.implode('; ', $parts) : '');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComponents::route('/'),
            'create' => Pages\CreateComponent::route('/create'),
            'edit' => Pages\EditComponent::route('/{record}/edit'),
        ];
    }
}
