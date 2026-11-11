<?php

namespace App\Filament\Resources\Subscribers;

use App\Filament\Concerns\AdminOnly;
use App\Models\Subscriber;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use UnitEnum;

class SubscriberResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = Subscriber::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedEnvelope;

    protected static string|UnitEnum|null $navigationGroup = 'Notifications';

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('email')->email()->required()->unique(ignoreRecord: true),
            DateTimePicker::make('verified_at')->label('Verified')
                ->helperText('Set to add someone without the confirmation e-mail.'),
            Select::make('components')->relationship('components', 'name')->multiple()->preload()
                ->helperText('Empty = all notifications.')->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('email')->searchable(),
                IconColumn::make('verified_at')->label('Verified')->boolean(),
                TextColumn::make('components.name')->badge()->color('gray')->limitList(3)->placeholder('All'),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->filters([
                TernaryFilter::make('verified_at')->label('Verified')->nullable(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSubscribers::route('/'),
            'create' => Pages\CreateSubscriber::route('/create'),
            'edit' => Pages\EditSubscriber::route('/{record}/edit'),
        ];
    }
}
