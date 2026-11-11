<?php

namespace App\Filament\Resources\Incidents\RelationManagers;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Services\IncidentManager;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

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
            MarkdownEditor::make('message')->required()->columnSpanFull(),
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
                    ->using(function (array $data): IncidentUpdate {
                        /** @var Incident $incident */
                        $incident = $this->getOwnerRecord();

                        return app(IncidentManager::class)->postUpdate(
                            $incident,
                            $data['status'] instanceof IncidentStatus ? $data['status'] : IncidentStatus::from($data['status']),
                            $data['message'],
                            auth()->user(),
                        );
                    })
                    ->after(fn () => $this->dispatch('refresh')),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data) => ['message' => $data['message']]),
                DeleteAction::make(),
            ]);
    }
}
