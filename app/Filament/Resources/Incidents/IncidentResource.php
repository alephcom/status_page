<?php

namespace App\Filament\Resources\Incidents;

use App\Ai\AiException;
use App\Ai\IncidentContext;
use App\Ai\IncidentWriter;
use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Services\IncidentManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class IncidentResource extends Resource
{
    protected static ?string $model = Incident::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 1;

    public static function getNavigationBadge(): ?string
    {
        $open = Incident::query()->unresolved()->count();

        return $open ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                Select::make('template')->label('Start from a template')
                    ->options(fn () => IncidentTemplate::query()->orderBy('name')->pluck('name', 'id'))
                    ->placeholder('No template')
                    ->visibleOn('create')
                    ->visible(fn (string $operation) => $operation === 'create' && IncidentTemplate::query()->exists())
                    ->dehydrated(false)
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set) {
                        if (! $state || ! ($template = IncidentTemplate::query()->find($state))) {
                            return;
                        }

                        $set('title', $template->title);
                        $set('message', $template->message);
                        $set('impact', $template->impact);
                        $set('component_status', $template->component_status?->value);
                        $set('component_ids', $template->existingComponentIds());
                    })
                    ->columnSpanFull(),
                TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
                Select::make('status')->options(IncidentStatus::class)->default(IncidentStatus::Investigating)
                    ->required()->selectablePlaceholder(false)
                    ->visibleOn('create')
                    ->helperText('Later changes of status are made by posting an update.'),
                Select::make('impact')->options(IncidentImpact::class)->default(IncidentImpact::Minor)->required()->selectablePlaceholder(false),
                DateTimePicker::make('started_at')->default(now())->required(),
                Toggle::make('visible')->default(true)->helperText('Hidden incidents are drafts: not shown and no notifications.'),
            ]),
            Section::make('Affected components')->columns(2)->schema([
                Select::make('component_ids')->label('Components')
                    ->multiple()
                    ->options(fn () => Component::query()->orderBy('name')->pluck('name', 'id'))
                    ->searchable(),
                Select::make('component_status')->label('Set their status to')
                    ->options(collect(ComponentStatus::cases())->reject(fn ($s) => $s === ComponentStatus::Operational)
                        ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()]))
                    ->placeholder('Leave to monitoring')
                    ->helperText('Applies while the incident is open; the worst of this and monitoring wins.'),
            ]),
            MarkdownEditor::make('message')->label('First update')->required()->visibleOn('create')
                ->default('We are investigating reports of a problem.')
                ->hintAction(self::draftIncidentAction())
                ->columnSpanFull(),
            Section::make('Post-incident review')
                ->description('A public write-up of what happened, shown on the incident page once published.')
                ->visible(fn (?Incident $record) => $record?->isResolved())
                ->collapsed(fn (?Incident $record) => blank($record?->postmortem))
                ->schema([
                    MarkdownEditor::make('postmortem')->hiddenLabel()
                        ->hintAction(self::draftPostmortemAction()),
                    Toggle::make('postmortem_published')->label('Published')
                        ->helperText('Shown on the incident page and in the feed and API.'),
                ]),
        ]);
    }

    /** Fill title, first update and impact from the selected components' monitoring problems. */
    public static function draftIncidentAction(): Action
    {
        return Action::make('draftWithAi')
            ->label('Draft with Claude')
            ->icon(Heroicon::OutlinedSparkles)
            ->visible(fn () => app(IncidentWriter::class)->enabled())
            ->action(function (Get $get, Set $set) {
                $components = Component::query()->whereIn('id', array_map('intval', $get('component_ids') ?? []))->get();

                try {
                    $draft = app(IncidentWriter::class)->draftIncident(IncidentContext::forComponents($components, $get('title')));
                } catch (AiException $e) {
                    Notification::make()->danger()->title('No draft')->body($e->getMessage())->send();

                    return;
                }

                $set('title', $draft['title']);
                $set('message', $draft['message']);
                $set('impact', IncidentImpact::tryFrom($draft['impact']) ?? IncidentImpact::Minor);
                Notification::make()->success()->title('Draft written')->body('Check it before saving: it is based on the selected components and their monitoring problems.')->send();
            });
    }

    public static function draftPostmortemAction(): Action
    {
        return Action::make('draftPostmortem')
            ->label('Draft with Claude')
            ->icon(Heroicon::OutlinedSparkles)
            ->visible(fn () => app(IncidentWriter::class)->enabled())
            ->requiresConfirmation(fn (Get $get) => filled($get('postmortem')))
            ->modalDescription('This replaces the text in the editor.')
            ->action(function (Set $set, ?Incident $record) {
                if (! $record) {
                    return;
                }

                try {
                    $set('postmortem', app(IncidentWriter::class)->draftPostmortem(IncidentContext::forIncident($record)));
                } catch (AiException $e) {
                    Notification::make()->danger()->title('No draft')->body($e->getMessage())->send();

                    return;
                }

                Notification::make()->success()->title('Draft written')->body('Fill in the marked placeholders and check every statement before publishing.')->send();
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('status')->badge(),
                TextColumn::make('impact')->badge(),
                TextColumn::make('components.name')->badge()->color('gray')->limitList(3),
                IconColumn::make('visible')->boolean(),
                TextColumn::make('recovered_at')->label('Monitoring')
                    ->state(fn (Incident $record) => $record->recovered_at && ! $record->isResolved() ? 'Recovered' : null)
                    ->badge()->color('success')
                    ->tooltip(fn (Incident $record) => $record->recovered_at ? 'Monitoring recovered '.$record->recovered_at->diffForHumans().'. Resolve it if the problem is over.' : null),
                IconColumn::make('auto_created')->boolean()->label('Auto')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')->dateTime()->sortable(),
                TextColumn::make('resolved_at')->dateTime()->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(IncidentStatus::class),
                TernaryFilter::make('open')->label('Open')
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('resolved_at'),
                        false: fn (Builder $q) => $q->whereNotNull('resolved_at'),
                    ),
                TernaryFilter::make('visible'),
            ])
            ->recordActions([
                Action::make('resolve')
                    ->icon(Heroicon::OutlinedCheckCircle)->color('success')
                    ->visible(fn (Incident $record) => $record->recovered_at && ! $record->isResolved())
                    ->schema([
                        MarkdownEditor::make('message')->required()
                            ->default('This incident has been resolved and services are operating normally.'),
                    ])
                    ->action(fn (Incident $record, array $data) => app(IncidentManager::class)
                        ->postUpdate($record, IncidentStatus::Resolved, $data['message'], auth()->user())),
                Action::make('publish')
                    ->icon(Heroicon::OutlinedEye)
                    ->visible(fn (Incident $record) => ! $record->visible)
                    ->requiresConfirmation()
                    ->modalDescription('The incident becomes public and subscribers are notified.')
                    ->action(function (Incident $record) {
                        $record->update(['visible' => true]);
                        app(IncidentManager::class)->edited($record, false);
                    }),
                EditAction::make(),
            ]);
    }

    public static function getRelations(): array
    {
        return [RelationManagers\UpdatesRelationManager::class, RelationManagers\ScheduledUpdatesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidents::route('/'),
            'create' => Pages\CreateIncident::route('/create'),
            'edit' => Pages\EditIncident::route('/{record}/edit'),
        ];
    }
}
