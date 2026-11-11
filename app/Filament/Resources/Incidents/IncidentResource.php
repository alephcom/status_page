<?php

namespace App\Filament\Resources\Incidents;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Services\IncidentManager;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
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
                ->columnSpanFull(),
        ]);
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
        return [RelationManagers\UpdatesRelationManager::class];
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
