<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'component_id', 'date', 'operational_seconds', 'degraded_seconds',
    'maintenance_seconds', 'partial_outage_seconds', 'major_outage_seconds',
])]
class ComponentStatusDaily extends Model
{
    protected $table = 'component_status_daily';

    public $timestamps = false;

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function secondsFor(ComponentStatus $status): int
    {
        return (int) $this->getAttribute($status->value.'_seconds');
    }

    public function trackedSeconds(): int
    {
        return array_sum(array_map(fn (ComponentStatus $s) => $this->secondsFor($s), ComponentStatus::cases()));
    }

    /**
     * Share of the tracked time the component was usable. Degraded and
     * maintenance time count as up; partial outages count as half down.
     */
    public function uptimePercent(): ?float
    {
        $total = $this->trackedSeconds();

        if ($total === 0) {
            return null;
        }

        $down = $this->secondsFor(ComponentStatus::MajorOutage) + 0.5 * $this->secondsFor(ComponentStatus::PartialOutage);

        return round(100 * (1 - $down / $total), 3);
    }

    /** The worst status the component had at any point that day. */
    public function worstStatus(): ?ComponentStatus
    {
        $present = array_filter(ComponentStatus::cases(), fn (ComponentStatus $s) => $this->secondsFor($s) > 0);

        return $present ? ComponentStatus::worst($present) : null;
    }

    /**
     * Bar segments for the uptime chart, worst status first. Each share is a
     * percentage of the bar's height, proportional to the time spent in that
     * status, except that any non-operational status gets at least
     * $minShare percent so a short blip stays visible.
     *
     * @return list<array{status: ComponentStatus, seconds: int, share: float}>
     */
    public function segments(float $minShare = 15.0): array
    {
        $total = $this->trackedSeconds();

        if ($total === 0) {
            return [];
        }

        $segments = [];
        foreach (array_reverse(ComponentStatus::ordered()) as $status) {
            $seconds = $this->secondsFor($status);
            if ($seconds > 0) {
                $share = 100 * $seconds / $total;
                $segments[] = [
                    'status' => $status,
                    'seconds' => $seconds,
                    'share' => $status->isOperational() ? $share : max($share, $minShare),
                ];
            }
        }

        // Boosting small segments overfills the bar; take the excess back
        // proportionally from the segments above the minimum.
        $excess = array_sum(array_column($segments, 'share')) - 100;
        if ($excess > 0) {
            $room = array_sum(array_map(fn ($s) => max(0, $s['share'] - $minShare), $segments));
            foreach ($segments as &$segment) {
                $segment['share'] -= $room > 0 ? $excess * max(0, $segment['share'] - $minShare) / $room : 0;
            }
            unset($segment);
        }

        return $segments;
    }
}
