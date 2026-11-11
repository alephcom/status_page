<?php

namespace App\Filament\Resources\IncidentTemplates;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Models\Component;
use App\Models\IncidentTemplate;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

/** Presets for recurring problems; pick one when creating an incident (or pass template_id via the API). */
class IncidentTemplateResource extends Resource
{
    protected static ?string $model = IncidentTemplate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentDuplicate;

    protected static string|UnitEnum|null $navigationGroup = 'Communication';

    protected static ?int $navigationSort = 3;

    protected static ?string $modelLabel = 'incident template';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->schema([
                TextInput::make('name')->required()->maxLength(255)->helperText('Only staff see this, e.g. "Database failover".'),
                TextInput::make('title')->label('Incident title')->required()->maxLength(255),
                Select::make('impact')->options(IncidentImpact::class)->default(IncidentImpact::Minor)->required()->selectablePlaceholder(false),
                Select::make('component_status')->label('Set components to')
                    ->options(collect(ComponentStatus::cases())->reject(fn ($s) => $s === ComponentStatus::Operational)
                        ->mapWithKeys(fn ($s) => [$s->value => $s->getLabel()]))
                    ->placeholder('Leave to monitoring'),
                Select::make('component_ids')->label('Components')->multiple()
                    ->options(fn () => Component::query()->orderBy('name')->pluck('name', 'id'))
                    ->columnSpanFull(),
                MarkdownEditor::make('message')->label('First update')->required()->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('title')->wrap(),
                TextColumn::make('impact')->badge(),
                TextColumn::make('updated_at')->since()->sortable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListIncidentTemplates::route('/'),
            'create' => Pages\CreateIncidentTemplate::route('/create'),
            'edit' => Pages\EditIncidentTemplate::route('/{record}/edit'),
        ];
    }
}
