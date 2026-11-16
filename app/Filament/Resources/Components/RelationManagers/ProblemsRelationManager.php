<?php

namespace App\Filament\Resources\Components\RelationManagers;

use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\ZabbixProblem;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Read-only view of the Zabbix problems that affected this component. */
class ProblemsRelationManager extends RelationManager
{
    protected static string $relationship = 'problems';

    protected static ?string $title = 'Monitoring problems';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('started_at', 'desc')
            ->columns([
                TextColumn::make('name')->label('Problem')->wrap()->searchable(),
                TextColumn::make('severity')->badge()
                    ->formatStateUsing(fn (ZabbixProblem $record) => $record->severityLabel())
                    ->color(fn (int $state) => $state >= 4 ? 'danger' : ($state >= 2 ? 'warning' : 'gray'))
                    ->sortable(),
                TextColumn::make('started_at')->label('Started')->dateTime()->sortable(),
                TextColumn::make('resolved_at')->label('Resolved')->dateTime()->placeholder('Active')->sortable(),
                TextColumn::make('eventid')->label('Zabbix event')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('active')
                    ->placeholder('All')->trueLabel('Active')->falseLabel('Resolved')
                    ->default(true)
                    ->queries(
                        true: fn (Builder $q) => $q->whereNull('resolved_at'),
                        false: fn (Builder $q) => $q->whereNotNull('resolved_at'),
                    ),
            ])
            ->headerActions([
                Action::make('draftIncident')
                    ->label('Draft incident')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->visible(fn () => $this->getOwnerRecord()->activeProblems()->exists())
                    ->url(fn () => IncidentResource::createUrlFor([$this->getOwnerRecord()->getKey()], draft: true)),
            ]);
    }
}
