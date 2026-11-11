<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentStatusDaily;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Monthly availability per component from the daily uptime figures.
 * Downtime counts major outages fully and partial outages as half, the
 * same rule as the uptime bars; degraded and maintenance time count as up.
 */
class SlaReport
{
    /**
     * @return Collection<int, array{component: Component, uptime: ?float, target: ?float, met: ?bool, downtime_minutes: int, partial_minutes: int, maintenance_minutes: int, days: int}>
     */
    public function forMonth(CarbonImmutable $month, bool $includeDisabled = false): Collection
    {
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();

        $components = Component::query()
            ->when(! $includeDisabled, fn ($q) => $q->where('enabled', true))
            ->with(['group', 'dailyStatus' => fn ($q) => $q->whereDate('date', '>=', $from)->whereDate('date', '<=', $to)])
            ->orderBy('order')->orderBy('name')
            ->get();

        return $components->map(function (Component $c) {
            $tracked = 0;
            $major = 0;
            $partial = 0;
            $maintenance = 0;

            $c->dailyStatus->each(function (ComponentStatusDaily $d) use (&$tracked, &$major, &$partial, &$maintenance) {
                $tracked += $d->trackedSeconds();
                $major += $d->secondsFor(ComponentStatus::MajorOutage);
                $partial += $d->secondsFor(ComponentStatus::PartialOutage);
                $maintenance += $d->secondsFor(ComponentStatus::Maintenance);
            });

            $uptime = $tracked > 0 ? round(100 * (1 - ($major + 0.5 * $partial) / $tracked), 3) : null;
            $target = $c->sla_target !== null ? (float) $c->sla_target : null;

            return [
                'component' => $c,
                'uptime' => $uptime,
                'target' => $target,
                'met' => $uptime !== null && $target !== null ? $uptime >= $target : null,
                'downtime_minutes' => (int) round($major / 60),
                'partial_minutes' => (int) round($partial / 60),
                'maintenance_minutes' => (int) round($maintenance / 60),
                'days' => $c->dailyStatus->count(),
            ];
        });
    }

    /** CSV of a month's report. */
    public function csv(CarbonImmutable $month): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['month', 'component', 'group', 'uptime_percent', 'sla_target_percent', 'sla_met', 'major_outage_minutes', 'partial_outage_minutes', 'maintenance_minutes', 'days_with_data'], escape: '');

        foreach ($this->forMonth($month) as $row) {
            fputcsv($out, [
                $month->format('Y-m'),
                $row['component']->name,
                $row['component']->group?->name,
                $row['uptime'],
                $row['target'],
                $row['met'] === null ? '' : ($row['met'] ? 'yes' : 'no'),
                $row['downtime_minutes'],
                $row['partial_minutes'],
                $row['maintenance_minutes'],
                $row['days'],
            ], escape: '');
        }

        rewind($out);

        return stream_get_contents($out);
    }
}
