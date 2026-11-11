<?php

namespace App\Support;

use App\Models\ComponentMetric;
use App\Models\MetricPoint;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Geometry for a small server-rendered line chart of one metric: a 2px line
 * over a faint area wash, scaled to a fixed viewBox. The points also go to
 * the browser for the hover readout, and to a table for screen readers.
 */
class MetricChart
{
    public const WIDTH = 600;

    public const HEIGHT = 60;

    /**
     * @param  Collection<int, MetricPoint>  $points  oldest first
     */
    public function __construct(public readonly ComponentMetric $metric, public readonly Collection $points) {}

    public function isEmpty(): bool
    {
        return $this->points->count() < 2;
    }

    public function latest(): ?float
    {
        return $this->points->last()?->value;
    }

    public function min(): float
    {
        return (float) $this->points->min('value');
    }

    public function max(): float
    {
        return (float) $this->points->max('value');
    }

    public function average(): ?float
    {
        return $this->points->isEmpty() ? null : (float) $this->points->avg('value');
    }

    /** @return list<array{0: float, 1: float}> x,y in viewBox units */
    public function coordinates(): array
    {
        $first = $this->points->first()->recorded_at->getTimestamp();
        $span = max(1, $this->points->last()->recorded_at->getTimestamp() - $first);
        // Baseline at zero so small wiggles don't look like spikes.
        $top = $this->max() > 0 ? $this->max() * 1.1 : 1;
        $pad = 3; // keep the 2px line inside the box

        return $this->points->map(fn (MetricPoint $p) => [
            round(($p->recorded_at->getTimestamp() - $first) / $span * self::WIDTH, 2),
            round($pad + (1 - $p->value / $top) * (self::HEIGHT - 2 * $pad), 2),
        ])->values()->all();
    }

    public function linePath(): string
    {
        return 'M'.collect($this->coordinates())->map(fn ($c) => $c[0].','.$c[1])->join(' L');
    }

    public function areaPath(): string
    {
        $coords = $this->coordinates();

        return $this->linePath().' L'.end($coords)[0].','.self::HEIGHT.' L'.$coords[0][0].','.self::HEIGHT.' Z';
    }

    /** @return list<array{t: string, v: string}> for the hover readout */
    public function readout(): array
    {
        return $this->points->map(fn (MetricPoint $p) => [
            't' => $p->recorded_at->toIso8601String(),
            'v' => $this->metric->format($p->value),
        ])->values()->all();
    }

    /**
     * Hourly averages for the accessible table.
     *
     * @return list<array{hour: CarbonInterface, value: string}>
     */
    public function hourly(): array
    {
        return $this->points
            ->groupBy(fn (MetricPoint $p) => $p->recorded_at->copy()->startOfHour()->getTimestamp())
            ->map(fn (Collection $g) => ['hour' => $g->first()->recorded_at->copy()->startOfHour(), 'value' => $this->metric->format($g->avg('value'))])
            ->values()
            ->all();
    }
}
