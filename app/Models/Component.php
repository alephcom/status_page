<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use App\Events\ComponentStatusChanged;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'component_group_id', 'name', 'description', 'link', 'order', 'enabled',
    'manual_status', 'manual_status_until',
])]
class Component extends Model
{
    use HasFactory;

    protected $attributes = [
        'enabled' => true,
        'order' => 0,
        'status' => 'operational',
        'auto_status' => 'operational',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'order' => 'integer',
            'status' => ComponentStatus::class,
            'auto_status' => ComponentStatus::class,
            'manual_status' => ComponentStatus::class,
            'manual_status_until' => 'datetime',
            'status_changed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Every component starts with a recorded status, so uptime has a baseline.
        static::created(function (Component $component) {
            $component->refresh();
            $component->statusChanges()->create([
                'from_status' => null,
                'status' => $component->status,
                'changed_at' => $component->created_at,
            ]);
            $component->forceFill(['status_changed_at' => $component->created_at])->saveQuietly();
            $component->refreshStatus();
        });

        // Manual overrides take effect as soon as they are saved.
        static::updated(function (Component $component) {
            if ($component->wasChanged(['manual_status', 'manual_status_until'])) {
                $component->refreshStatus();
            }
        });
    }

    /** @param Builder<Component> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /** @return BelongsTo<ComponentGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class, 'component_group_id');
    }

    /** @return HasMany<ComponentSource, $this> */
    public function sources(): HasMany
    {
        return $this->hasMany(ComponentSource::class);
    }

    /** @return HasMany<ComponentStatusChange, $this> */
    public function statusChanges(): HasMany
    {
        return $this->hasMany(ComponentStatusChange::class);
    }

    /** @return HasMany<ComponentStatusDaily, $this> */
    public function dailyStatus(): HasMany
    {
        return $this->hasMany(ComponentStatusDaily::class);
    }

    /** @return HasMany<ZabbixProblem, $this> */
    public function problems(): HasMany
    {
        return $this->hasMany(ZabbixProblem::class);
    }

    /** @return HasMany<ZabbixProblem, $this> */
    public function activeProblems(): HasMany
    {
        return $this->problems()->whereNull('resolved_at')->orderByDesc('severity');
    }

    /** @return BelongsToMany<Incident, $this> */
    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class);
    }

    /** @return BelongsToMany<Maintenance, $this> */
    public function maintenances(): BelongsToMany
    {
        return $this->belongsToMany(Maintenance::class);
    }

    /** @return BelongsToMany<Subscriber, $this> */
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class);
    }

    public function hasActiveManualOverride(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        return $this->manual_status !== null
            && ($this->manual_status_until === null || $this->manual_status_until->greaterThan($now));
    }

    /**
     * Work out the public status from, in order of precedence: a manual
     * override; an active maintenance window; and otherwise the monitored
     * status. Open incidents that set a component status are combined with
     * either of the latter two and the worst one wins.
     */
    public function computeStatus(?CarbonInterface $now = null): ComponentStatus
    {
        $now ??= now();

        if ($this->hasActiveManualOverride($now)) {
            return $this->manual_status;
        }

        $inMaintenance = $this->maintenances()
            ->where('scheduled_start', '<=', $now)
            ->where('scheduled_end', '>', $now)
            ->exists();

        $incidentStatuses = $this->incidents()
            ->whereNull('resolved_at')
            ->whereNotNull('component_status')
            ->pluck('component_status')
            ->map(fn (ComponentStatus|string $status) => $status instanceof ComponentStatus ? $status : ComponentStatus::from($status));

        $base = $inMaintenance ? ComponentStatus::Maintenance : ($this->auto_status ?? ComponentStatus::Operational);

        return ComponentStatus::worst($incidentStatuses->push($base));
    }

    /**
     * Recompute and persist the public status, recording the change and
     * notifying listeners when it differs.
     */
    public function refreshStatus(?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $new = $this->computeStatus($now);
        $old = $this->status;

        if ($old === $new) {
            return false;
        }

        $this->forceFill(['status' => $new, 'status_changed_at' => $now])->saveQuietly();

        $this->statusChanges()->create([
            'from_status' => $old?->value,
            'status' => $new->value,
            'changed_at' => $now,
        ]);

        ComponentStatusChanged::dispatch($this, $old, $new);

        return true;
    }
}
