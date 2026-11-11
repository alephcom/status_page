<?php

namespace App\Filament\Pages;

use App\Ai\IncidentWriter;
use App\Filament\Concerns\AdminOnly;
use App\Http\Middleware\SetLocale;
use App\Models\Setting;
use BackedEnum;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\ValidationException;
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
                    Toggle::make('auto_resolve_recovered_drafts')->label('Resolve those drafts by themselves when monitoring recovers')
                        ->helperText('Only hidden drafts that monitoring opened. Published incidents are flagged "Recovered" for you to resolve.'),
                    Toggle::make('ai_drafts')->label('Let Claude write the text of automatic drafts')
                        ->helperText(app(IncidentWriter::class)->enabled()
                            ? 'Uses the monitoring problems to write a public title and first update. You still review and publish.'
                            : 'Set ANTHROPIC_API_KEY in .env to enable AI drafting.'),
                    Toggle::make('notify_component_changes')->label('E-mail subscribers when a component breaks or recovers')
                        ->helperText('Off by default: monitoring flaps can be noisy. Webhooks always get these events.'),
                ]),
                Section::make('Maintenance notifications')->columns(2)->schema([
                    TextInput::make('maintenance_reminder_minutes')->label('Remind subscribers before maintenance (minutes)')
                        ->numeric()->minValue(0)->maxValue(10080)->required()
                        ->helperText('0 turns reminders off.'),
                    Toggle::make('notify_maintenance_lifecycle')->label('E-mail subscribers when maintenance starts and ends')
                        ->helperText('Webhooks always get maintenance.started and maintenance.completed.'),
                ]),
                Section::make('Alerts for admins')->schema([
                    TextInput::make('stale_alert_minutes')->label('E-mail admins when monitoring data has been stale for (minutes)')
                        ->numeric()->minValue(0)->maxValue(1440)->required()
                        ->helperText('0 turns the alert off. A second e-mail says when it recovers. For outside monitoring of this page, use /health.'),
                ]),
                Section::make('Branding')->columns(2)->collapsible()->schema([
                    FileUpload::make('logo_path')->label('Logo')->image()->disk('public')->directory('branding')
                        ->maxSize(1024)->helperText('Shown next to the site name and as the favicon. Needs `php artisan storage:link`.'),
                    ColorPicker::make('brand_color')->label('Accent colour')->helperText('Links and buttons. Status colours do not change.'),
                    Select::make('default_theme')->label('Default theme')->options(['auto' => 'Follow the visitor\'s system', 'light' => 'Light', 'dark' => 'Dark'])
                        ->selectablePlaceholder(false)->required()->helperText('Visitors can still switch in the footer.'),
                    Repeater::make('footer_links')->label('Footer links')
                        ->schema([TextInput::make('name')->required(), TextInput::make('url')->url()->required()])
                        ->columns(2)->defaultItems(0)->reorderable()->columnSpanFull(),
                    Textarea::make('custom_css')->label('Custom CSS')->rows(5)->columnSpanFull()
                        ->helperText('Added to every public page. Override variables such as --ok or --bg in :root.'),
                ]),
                Section::make('Announcement banner')->columns(2)->collapsible()->schema([
                    Textarea::make('announcement')->label('Text')->rows(2)->columnSpanFull()
                        ->helperText('Markdown. Shown at the top of every public page. Leave empty for none.'),
                    Select::make('announcement_level')->label('Style')->options(['info' => 'Information', 'warning' => 'Warning', 'danger' => 'Important'])
                        ->selectablePlaceholder(false)->required(),
                    DateTimePicker::make('announcement_until')->label('Hide after')->helperText('Optional.'),
                ]),
                Section::make('Languages')->columns(2)->collapsible()->schema([
                    CheckboxList::make('enabled_locales')->label('Offered on the public page')
                        ->options(SetLocale::AVAILABLE)->required()->columns(3),
                    Select::make('default_locale')->label('Default')->options(SetLocale::AVAILABLE)->required()
                        ->selectablePlaceholder(false)
                        ->helperText('Used when the visitor\'s browser asks for none of the offered languages.'),
                ]),
                Section::make('Private components')->collapsible()->schema([
                    TagsInput::make('private_ip_ranges')->label('Networks that can see private components')
                        ->placeholder('e.g. 10.0.0.0/8, then Enter')
                        ->helperText('Signed-in staff always see them. Behind a proxy, make sure Laravel trusts it so the visitor IP is right.'),
                ]),
            ]);
    }

    private static function validRange(string $value): bool
    {
        [$ip, $bits] = array_pad(explode('/', $value, 2), 2, null);

        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $max = str_contains($ip, ':') ? 128 : 32;

        return $bits === null || (ctype_digit($bits) && (int) $bits <= $max);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        foreach ($state['private_ip_ranges'] ?? [] as $range) {
            if (! self::validRange((string) $range)) {
                throw ValidationException::withMessages(['data.private_ip_ranges' => "\"{$range}\" is not an IP address or CIDR range."]);
            }
        }

        if (! in_array($state['default_locale'] ?? 'en', $state['enabled_locales'] ?? [], true)) {
            throw ValidationException::withMessages(['data.default_locale' => 'The default language must be one of the offered languages.']);
        }

        Setting::putMany($state);

        Notification::make()->success()->title('Settings saved')->send();
    }
}
