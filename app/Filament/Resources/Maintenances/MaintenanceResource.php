<?php

namespace App\Filament\Resources\Maintenances;

use App\Enums\MaintenanceStatus;
use App\Enums\Recurrence;
use App\Models\Maintenance;
use App\Services\MaintenanceManager;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class MaintenanceResource extends Resource
{
    protected static ?string $model = Maintenance::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'maintenance';

    protected static ?string $pluralModelLabel = 'maintenance';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')->required()->maxLength(255)->columnSpanFull(),
            DateTimePicker::make('scheduled_start')->required()->seconds(false),
            DateTimePicker::make('scheduled_end')->required()->seconds(false)
                ->after('scheduled_start'),
            Select::make('components')->relationship('components', 'name')->multiple()->preload()
                ->helperText('Shown as "Under maintenance" during the window.')
                ->columnSpanFull(),
            MarkdownEditor::make('message')->columnSpanFull(),
            Select::make('recurrence')->label('Repeat')->options(Recurrence::class)->placeholder('Does not repeat')
                ->live()
                ->helperText('When a window ends, the next one is created with the same details. Subscribers get reminders and start/end notices for each, but only one "scheduled" e-mail.'),
            DateTimePicker::make('recurrence_until')->label('Repeat until')->seconds(false)
                ->visible(fn (Get $get) => filled($get('recurrence')))
                ->helperText('Optional.'),
            Toggle::make('visible')->default(true)
                ->helperText(fn (Get $get, ?Maintenance $record) => $record?->notified
                    ? 'Subscribers have already been notified.'
                    : 'Subscribers are notified when a visible maintenance is saved.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_start', 'desc')
            ->columns([
                TextColumn::make('title')->searchable()->wrap(),
                TextColumn::make('status')->badge()
                    ->state(fn (Maintenance $record): MaintenanceStatus => $record->status),
                TextColumn::make('scheduled_start')->dateTime()->sortable(),
                TextColumn::make('scheduled_end')->dateTime()->sortable(),
                TextColumn::make('components.name')->badge()->color('gray')->limitList(3),
                TextColumn::make('recurrence')->label('Repeats')->badge()->color('gray')->placeholder('—'),
                IconColumn::make('visible')->boolean(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->before(fn (Maintenance $record) => app(MaintenanceManager::class)->deleting($record)),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMaintenances::route('/'),
            'create' => Pages\CreateMaintenance::route('/create'),
            'edit' => Pages\EditMaintenance::route('/{record}/edit'),
        ];
    }
}
