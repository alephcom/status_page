<?php

namespace App\Filament\Resources\Incidents\RelationManagers;

use App\Ai\AiException;
use App\Ai\IncidentContext;
use App\Ai\IncidentWriter;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\ScheduledIncidentUpdate;
use App\Services\IncidentManager;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class UpdatesRelationManager extends RelationManager
{
    protected static string $relationship = 'updates';

    protected static ?string $title = 'Timeline';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')->options(IncidentStatus::class)->required()->selectablePlaceholder(false)
                ->default(fn () => $this->getOwnerRecord()->status)
                ->disabledOn('edit'),
            DateTimePicker::make('publish_at')->label('Post at')
                ->visibleOn('create')
                ->seconds(false)
                ->minDate(now())
                ->helperText('Optional. Leave empty to post now; set a time to post it automatically later.'),
            MarkdownEditor::make('message')->required()->columnSpanFull()
                ->hintAction(
                    Action::make('draftUpdate')
                        ->label('Draft with Claude')
                        ->icon(Heroicon::OutlinedSparkles)
                        ->visible(fn (string $operation) => $operation === 'create' && app(IncidentWriter::class)->enabled())
                        ->action(function (Set $set) {
                            try {
                                $draft = app(IncidentWriter::class)->draftUpdate(IncidentContext::forIncident($this->getOwnerRecord()));
                            } catch (AiException $e) {
                                Notification::make()->danger()->title('No draft')->body($e->getMessage())->send();

                                return;
                            }

                            $set('message', $draft['message']);
                            if ($status = IncidentStatus::tryFrom($draft['status'])) {
                                $set('status', $status);
                            }
                        }),
                ),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('status')->badge(),
                TextColumn::make('message')->markdown()->wrap()->limit(300),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()->label('Post update')
                    ->using(function (array $data): Model {
                        /** @var Incident $incident */
                        $incident = $this->getOwnerRecord();
                        $status = $data['status'] instanceof IncidentStatus ? $data['status'] : IncidentStatus::from($data['status']);

                        if (filled($data['publish_at'] ?? null) && Carbon::parse($data['publish_at'])->isFuture()) {
                            return app(IncidentManager::class)->scheduleUpdate($incident, $status, $data['message'], Carbon::parse($data['publish_at']), auth()->user());
                        }

                        return app(IncidentManager::class)->postUpdate($incident, $status, $data['message'], auth()->user());
                    })
                    ->successNotificationTitle(fn (Model $record) => $record instanceof ScheduledIncidentUpdate
                        ? 'Update scheduled for '.$record->publish_at->toDayDateTimeString()
                        : 'Update posted')
                    ->after(fn () => $this->dispatch('refresh')),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => ['message' => $data['message']]),
                DeleteAction::make(),
            ]);
    }
}
