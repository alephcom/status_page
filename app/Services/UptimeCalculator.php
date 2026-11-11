<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentStatusChange;
use App\Models\ComponentStatusDaily;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Turns the status change log into per-day seconds per status. Days are
 * calendar days in the application timezone.
 */
class UptimeCalculator
{
    /**
     * Recompute the daily rows for one component over [from, to] (dates).
     */
    public function rollup(Component $component, CarbonInterface $from, CarbonInterface $to, ?CarbonInterface $now = null): void
    {
        $now = CarbonImmutable::instance($now ?? now());
        $day = CarbonImmutable::instance($from)->startOfDay();
        $last = CarbonImmutable::instance($to)->startOfDay();
        $since = CarbonImmutable::instance($component->created_at);

        $changes = $component->statusChanges()
            ->where('changed_at', '<', $last->addDay())
            ->orderBy('changed_at')->orderBy('id')
            ->get(['status', 'changed_at']);

        while ($day->lessThanOrEqualTo($last)) {
            $start = $day->max($since);
            $end = $day->addDay()->min($now);

            if ($start->lessThan($end)) {
                $seconds = $this->secondsPerStatus($changes, $start, $end);
                // whereDate: the stored format of a date column differs per database.
                $row = ComponentStatusDaily::query()->where('component_id', $component->id)->whereDate('date', $day)->first()
                    ?? new ComponentStatusDaily(['component_id' => $component->id, 'date' => $day]);
                $row->fill(collect($seconds)->mapWithKeys(fn ($s, $status) => ["{$status}_seconds" => $s])->all())->save();
            }

            $day = $day->addDay();
        }
    }

    /**
     * @param  Collection<int, ComponentStatusChange>  $changes  ordered by changed_at
     * @return array<string, int>
     */
    public function secondsPerStatus(Collection $changes, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $totals = array_fill_keys(array_map(fn ($s) => $s->value, ComponentStatus::cases()), 0);

        // Status in force at the start of the window: the last change before it.
        $current = $changes->filter(fn ($c) => $c->changed_at->lessThanOrEqualTo($start))->last()?->status;
        $cursor = $start;

        foreach ($changes as $change) {
            if ($change->changed_at->lessThanOrEqualTo($start)) {
                continue;
            }

            if ($change->changed_at->greaterThanOrEqualTo($end)) {
                break;
            }

            if ($current !== null) {
                $totals[$current->value] += (int) $cursor->diffInSeconds($change->changed_at, true);
            }

            $current = $change->status;
            $cursor = CarbonImmutable::instance($change->changed_at);
        }

        if ($current !== null) {
            $totals[$current->value] += (int) $cursor->diffInSeconds($end, true);
        }

        return $totals;
    }

    /**
     * Daily rows for the last $days days (oldest first), with nulls for days
     * without data.
     *
     * @return list<array{date: CarbonImmutable, row: ?ComponentStatusDaily}>
     */
    public function history(Component $component, int $days): array
    {
        $today = CarbonImmutable::today();
        $first = $today->subDays($days - 1);

        $rows = $component->relationLoaded('dailyStatus')
            ? $component->dailyStatus
            : $component->dailyStatus()->where('date', '>=', $first->toDateString())->get();

        $byDate = $rows->keyBy(fn (ComponentStatusDaily $r) => $r->date->toDateString());

        $out = [];
        for ($d = $first; $d->lessThanOrEqualTo($today); $d = $d->addDay()) {
            $out[] = ['date' => $d, 'row' => $byDate->get($d->toDateString())];
        }

        return $out;
    }

    /** Overall uptime over the given rows, or null without data. */
    public function overallPercent(array $history): ?float
    {
        $total = 0;
        $down = 0.0;

        foreach ($history as $day) {
            $row = $day['row'];
            if (! $row) {
                continue;
            }
            $total += $row->trackedSeconds();
            $down += $row->secondsFor(ComponentStatus::MajorOutage) + 0.5 * $row->secondsFor(ComponentStatus::PartialOutage);
        }

        return $total > 0 ? round(100 * (1 - $down / $total), 2) : null;
    }
}
