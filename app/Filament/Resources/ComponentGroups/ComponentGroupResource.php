<?php

namespace App\Filament\Resources\ComponentGroups;

use App\Filament\Concerns\AdminOnly;
use App\Models\ComponentGroup;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class ComponentGroupResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = ComponentGroup::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = 'Components';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'group';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('order')->numeric()->default(0),
            Textarea::make('description')->rows(2)->columnSpanFull(),
            Toggle::make('collapsed')->helperText('Collapsed on the public page while all its components are operational.'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('order')
            ->reorderable('order')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('components_count')->counts('components')->label('Components'),
                IconColumn::make('collapsed')->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListComponentGroups::route('/'),
            'create' => Pages\CreateComponentGroup::route('/create'),
            'edit' => Pages\EditComponentGroup::route('/{record}/edit'),
        ];
    }
}
