<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\AdminOnly;
use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class ManageSettings extends Page
{
    use AdminOnly;

    protected string $view = 'filament.pages.manage-settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'Settings';

    protected static ?string $title = 'Status page settings';

    protected static ?string $navigationLabel = 'Status page';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill(collect(Setting::DEFAULTS)->mapWithKeys(fn ($default, $key) => [$key => Setting::get($key, $default)])->all());
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('Public page')->columns(2)->schema([
                    TextInput::make('site_name')->required()->maxLength(100),
                    TextInput::make('history_days')->label('Show resolved incidents for (days)')->numeric()->minValue(1)->maxValue(90)->required(),
                    MarkdownEditor::make('site_about')->label('Introduction')->columnSpanFull(),
                    Repeater::make('external_links')->label('Other status pages')
                        ->schema([
                            TextInput::make('name')->required(),
                            TextInput::make('url')->url()->required(),
                            TextInput::make('description'),
                        ])
                        ->columns(3)->defaultItems(0)->reorderable()->columnSpanFull(),
                ]),
                Section::make('Automation')->schema([
                    Toggle::make('auto_incidents')->label('Draft an incident when a component goes into a major outage')
                        ->helperText('The draft is hidden; review and publish it from Incidents.'),
                    Toggle::make('notify_component_changes')->label('E-mail subscribers when a component breaks or recovers')
                        ->helperText('Off by default: monitoring flaps can be noisy. Webhooks always get these events.'),
                ]),
            ]);
    }

    public function save(): void
    {
        Setting::putMany($this->form->getState());

        Notification::make()->success()->title('Settings saved')->send();
    }
}
