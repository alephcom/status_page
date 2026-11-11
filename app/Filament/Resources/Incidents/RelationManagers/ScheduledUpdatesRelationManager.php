<?php

namespace App\Filament\Resources\Incidents\RelationManagers;

use App\Enums\IncidentStatus;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Updates written ahead of time; `statuspage:tick` posts them when due. */
class ScheduledUpdatesRelationManager extends RelationManager
{
    protected static string $relationship = 'scheduledUpdates';

    protected static ?string $title = 'Scheduled updates';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord->scheduledUpdates()->exists();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')->options(IncidentStatus::class)->required()->selectablePlaceholder(false),
            DateTimePicker::make('publish_at')->label('Post at')->required()->seconds(false)->after('now'),
            MarkdownEditor::make('message')->required()->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('publish_at')
            ->emptyStateHeading('Nothing scheduled')
            ->columns([
                TextColumn::make('publish_at')->label('Posts at')->dateTime()->description(fn ($record) => $record->publish_at->diffForHumans()),
                TextColumn::make('status')->badge(),
                TextColumn::make('message')->markdown()->wrap()->limit(200),
                TextColumn::make('user.name')->label('By')->placeholder('System'),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->label('Cancel'),
            ]);
    }
}
