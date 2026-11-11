<?php

namespace App\Filament\Pages;

use App\Services\SlaReport;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class SlaReportPage extends Page
{
    protected string $view = 'filament.pages.sla-report';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPresentationChartLine;

    protected static string|UnitEnum|null $navigationGroup = 'Components';

    protected static ?string $title = 'SLA report';

    protected static ?string $slug = 'sla-report';

    #[Url]
    public string $month = '';

    public static function canAccess(): bool
    {
        return auth()->user()?->role !== null;
    }

    public function mount(): void
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $this->month)) {
            $this->month = now()->subMonthNoOverflow()->format('Y-m');
        }
    }

    /** @return array<string, string> */
    public function months(): array
    {
        return collect(range(0, 23))
            ->mapWithKeys(fn (int $i) => [now()->startOfMonth()->subMonths($i)->format('Y-m') => now()->startOfMonth()->subMonths($i)->format('F Y')])
            ->all();
    }

    public function selectedMonth(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $this->month);
    }

    public function rows(): Collection
    {
        return app(SlaReport::class)->forMonth($this->selectedMonth());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('csv')->label('Download CSV')->icon(Heroicon::OutlinedArrowDownTray)->color('gray')
                ->action(fn (): StreamedResponse => response()->streamDownload(
                    function () {
                        echo app(SlaReport::class)->csv($this->selectedMonth());
                    },
                    "sla-{$this->month}.csv",
                    ['Content-Type' => 'text/csv; charset=UTF-8'],
                )),
            Action::make('print')->label('Print / PDF')->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn () => route('filament.admin.sla.print', ['month' => $this->month]), shouldOpenInNewTab: true),
        ];
    }
}
