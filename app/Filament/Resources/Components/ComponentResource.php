<?php

namespace App\Filament\Resources\Components;

use App\Enums\ComponentStatus;
use App\Filament\Concerns\AdminOnly;
use App\Filament\Support\ZabbixLookup;
use App\Models\Component;
use App\StatusSources\StatusSourceManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Section;
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
                                ->helperText('Searches host and visible names in Zabbix.'),
                            Select::make('config.host_groups')->label('Zabbix host groups')
                                ->multiple()
                                ->searchable()
                                ->searchDebounce(400)
                                ->searchPrompt('Type at least 2 characters to search Zabbix')
                                ->getSearchResultsUsing(fn (?string $search) => ZabbixLookup::hostGroups($search))
                                ->getOptionLabelsUsing(fn (array $values) => ZabbixLookup::labels($values)),
                            Repeater::make('config.tags')->label('Extra required trigger tags')
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
                ToggleColumn::make('enabled'),
            ])
            ->filters([
                SelectFilter::make('status')->options(ComponentStatus::class),
                SelectFilter::make('component_group_id')->label('Group')->relationship('group', 'name'),
            ])
            ->recordActions([
                self::testAction(),
                EditAction::make(),
            ]);
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
