<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Incidents\IncidentResource;
use App\Models\ZabbixProblem;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** What monitoring says is failing right now, with a shortcut to draft an incident. */
class ActiveProblems extends TableWidget
{
    protected static ?int $sort = 0;

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '30s';

    public function table(Table $table): Table
    {
        return $table
            ->heading('Active monitoring problems')
            ->description('Internal: raw problem names from Zabbix. Only staff see these.')
            ->query(fn (): Builder => ZabbixProblem::query()
                ->whereNull('resolved_at')
                ->whereHas('component', fn (Builder $q) => $q->enabled())
                ->with('component'))
            ->defaultSort('severity', 'desc')
            ->columns([
                TextColumn::make('component.name')->label('Component')->sortable(),
                TextColumn::make('name')->label('Problem')->wrap()->searchable(),
                TextColumn::make('severity')->badge()->sortable()
                    ->formatStateUsing(fn (ZabbixProblem $record) => $record->severityLabel())
                    ->color(fn (int $state) => match (true) {
                        $state >= 4 => 'danger',
                        $state >= 2 => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('started_at')->label('Since')->since()->sortable()
                    ->tooltip(fn (ZabbixProblem $record) => $record->started_at->toDayDateTimeString()),
            ])
            ->recordActions([
                Action::make('draftIncident')
                    ->label('Draft incident')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->url(fn (ZabbixProblem $record) => IncidentResource::createUrlFor([$record->component_id], draft: true)),
            ])
            ->toolbarActions([
                BulkAction::make('draftIncidentForSelected')
                    ->label('Draft incident for selected')
                    ->icon(Heroicon::OutlinedSparkles)
                    ->action(fn (Collection $records) => $this->redirect(
                        IncidentResource::createUrlFor($records->pluck('component_id'), draft: true)
                    )),
            ])
            ->emptyStateHeading('Nothing failing')
            ->emptyStateDescription('Monitoring reports no active problems.')
            ->paginated([10, 25, 50]);
    }
}
