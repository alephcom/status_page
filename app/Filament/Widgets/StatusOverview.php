<?php

namespace App\Filament\Widgets;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Services\StatusPageData;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatusOverview extends StatsOverviewWidget
{
    protected static ?int $sort = -1;

    protected ?string $pollingInterval = '30s';

    protected function getStats(): array
    {
        $lastOk = Setting::get('zabbix_last_ok_at');
        $error = Setting::get('zabbix_last_error');
        $stale = app(StatusPageData::class)->staleness() !== null;

        $notOk = Component::query()->enabled()->where('status', '!=', ComponentStatus::Operational->value)->count();
        $total = Component::query()->enabled()->count();
        $open = Incident::query()->unresolved()->count();
        $drafts = Incident::query()->unresolved()->where('visible', false)->count();

        return [
            Stat::make('Monitoring', $stale ? 'Stale' : 'OK')
                ->description($error ?: ($lastOk ? 'Last poll '.CarbonImmutable::parse($lastOk)->diffForHumans() : 'No successful poll yet'))
                ->color($stale ? 'danger' : 'success'),
            Stat::make('Components not operational', "{$notOk} / {$total}")
                ->color($notOk ? 'warning' : 'success'),
            Stat::make('Open incidents', (string) $open)
                ->description($drafts ? "{$drafts} hidden draft(s) to review" : null)
                ->color($open ? 'danger' : 'success'),
        ];
    }
}
