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
}
