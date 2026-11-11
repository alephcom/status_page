<?php

namespace App\Filament\Resources\AuditLogs;

use App\Filament\Concerns\AdminOnly;
use App\Models\AuditLog;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use UnitEnum;

/** Read-only history of changes made in the admin panel, over MCP and the API, and by the system. */
class AuditLogResource extends Resource
{
    use AdminOnly;

    protected static ?string $model = AuditLog::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $modelLabel = 'audit entry';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('created_at')->dateTime(),
            TextEntry::make('user.email')->label('By')->placeholder('System'),
            TextEntry::make('via'),
            TextEntry::make('ip')->label('IP')->placeholder('—'),
            TextEntry::make('action')->badge(),
            TextEntry::make('subject_type')->label('What')->formatStateUsing(fn (AuditLog $record) => "{$record->subject_type} #{$record->subject_id} – {$record->subject_label}"),
            KeyValueEntry::make('changes')->columnSpanFull()
                ->state(fn (AuditLog $record) => collect($record->changes ?? [])->map(fn ($v) => self::describe($record->action, $v))->all())
                ->keyLabel('Field')->valueLabel('Value (old → new for updates)'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('user.email')->label('By')->placeholder('System')->searchable(),
                TextColumn::make('via')->badge()->color('gray'),
                TextColumn::make('action')->badge()->color(fn (string $state) => match ($state) {
                    'created' => 'success', 'deleted' => 'danger', default => 'info',
                }),
                TextColumn::make('subject_type')->label('Type'),
                TextColumn::make('subject_label')->label('Subject')->searchable()->wrap()->limit(60),
                TextColumn::make('changes')->label('Fields')
                    ->state(fn (AuditLog $record) => implode(', ', array_keys($record->changes ?? [])))
                    ->limit(60)->toggleable(),
            ])
            ->filters([
                SelectFilter::make('subject_type')->label('Type')
                    ->options(fn () => AuditLog::query()->distinct()->orderBy('subject_type')->pluck('subject_type', 'subject_type')),
                SelectFilter::make('via')->options(['panel' => 'Admin panel', 'mcp' => 'MCP', 'api' => 'REST API', 'system' => 'System']),
                SelectFilter::make('action')->options(['created' => 'Created', 'updated' => 'Updated', 'deleted' => 'Deleted']),
                SelectFilter::make('user_id')->label('User')->relationship('user', 'email'),
            ])
            ->recordActions([ViewAction::make()]);
    }

    private static function describe(string $action, mixed $value): string
    {
        $show = fn ($v) => is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $action === 'updated' && is_array($value) && array_is_list($value) && count($value) === 2
            ? $show($value[0]).' → '.$show($value[1])
            : $show($value);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAuditLogs::route('/'),
        ];
    }
}
