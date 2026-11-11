<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use App\Enums\ComponentVisibility;
use App\Events\ComponentOverrideChanged;
use App\Events\ComponentStatusChanged;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable([
    'component_group_id', 'name', 'description', 'link', 'order', 'enabled',
    'manual_status', 'manual_status_until', 'sla_target', 'visibility',
])]
class Component extends Model
{
    use Auditable, HasFactory;

    /** @var array<int, true> components being refreshed, to stop dependency cycles */
    private static array $refreshing = [];

    protected $attributes = [
        'enabled' => true,
        'order' => 0,
        'status' => 'operational',
        'auto_status' => 'operational',
        'visibility' => 'public',
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
            'sla_target' => 'float',
            'visibility' => ComponentVisibility::class,
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
                ComponentOverrideChanged::dispatch($component);
            }
        });
    }

    /** @param Builder<Component> $query */
    public function scopeEnabled(Builder $query): void
    {
        $query->where('enabled', true);
    }

    /** @param Builder<Component> $query */
    public function scopePublic(Builder $query): void
    {
        $query->where('visibility', ComponentVisibility::Public->value);
    }

    public function isPrivate(): bool
    {
        return $this->visibility === ComponentVisibility::Private;
    }

    public function auditExclude(): array
    {
        return ['status', 'auto_status', 'status_changed_at'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    /**
     * Components this one relies on: their outages show here too.
     *
     * @return BelongsToMany<Component, $this>
     */
    public function dependencies(): BelongsToMany
    {
        return $this->belongsToMany(Component::class, 'component_dependencies', 'component_id', 'depends_on_id');
    }

    /** @return BelongsToMany<Component, $this> */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(Component::class, 'component_dependencies', 'depends_on_id', 'component_id');
    }

    /** @return HasMany<ComponentMetric, $this> */
    public function metrics(): HasMany
    {
        return $this->hasMany(ComponentMetric::class)->orderBy('order')->orderBy('id');
    }

    /**
     * Would making this component depend on $candidateId create a cycle?
     */
    public function wouldCycle(int $candidateId): bool
    {
        if ($candidateId === $this->id) {
            return true;
        }

        $seen = [];
        $queue = [$candidateId];

        while ($queue !== []) {
            $id = array_shift($queue);

            if ($id === $this->id) {
                return true;
            }

            if (isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $queue = [...$queue, ...DB::table('component_dependencies')->where('component_id', $id)->pluck('depends_on_id')->map(fn ($v) => (int) $v)->all()];
        }

        return false;
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
     * status. Open incidents that set a component status, and the status of
     * enabled components this one depends on, are combined with either of
     * the latter two and the worst one wins.
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

        $dependencyStatuses = $this->dependencies()->where('enabled', true)->pluck('status')
            ->map(fn (ComponentStatus|string $status) => $status instanceof ComponentStatus ? $status : ComponentStatus::from($status));

        $base = $inMaintenance ? ComponentStatus::Maintenance : ($this->auto_status ?? ComponentStatus::Operational);

        return ComponentStatus::worst($incidentStatuses->concat($dependencyStatuses)->push($base));
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

        $this->refreshDependents($now);

        return true;
    }

    /** Recompute components that depend on this one, guarding against cycles. */
    private function refreshDependents(CarbonInterface $now): void
    {
        if (isset(self::$refreshing[$this->id])) {
            return;
        }

        self::$refreshing[$this->id] = true;

        try {
            $this->dependents()->get()->each(function (Component $dependent) use ($now): void {
                $dependent->refreshStatus($now);
            });
        } finally {
            unset(self::$refreshing[$this->id]);
        }
    }
}
