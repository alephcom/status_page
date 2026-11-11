<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'title', 'status', 'impact', 'visible', 'auto_created', 'zabbix_eventid',
    'component_status', 'started_at', 'resolved_at',
])]
class Incident extends Model
{
    use HasFactory;

    protected $attributes = [
        'status' => 'investigating',
        'impact' => 'minor',
        'visible' => true,
        'auto_created' => false,
    ];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'impact' => IncidentImpact::class,
            'component_status' => ComponentStatus::class,
            'visible' => 'boolean',
            'auto_created' => 'boolean',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Incident $incident) {
            if ($incident->status === IncidentStatus::Resolved) {
                $incident->resolved_at ??= now();
            } else {
                $incident->resolved_at = null;
            }
        });
    }

    /** @param Builder<Incident> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->where('visible', true);
    }

    /** @param Builder<Incident> $query */
    public function scopeUnresolved(Builder $query): void
    {
        $query->whereNull('resolved_at');
    }

    /** @return BelongsToMany<Component, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Component::class);
    }

    /** @return HasMany<IncidentUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->latest()->latest('id');
    }

    /** @return HasOne<IncidentUpdate, $this> */
    public function latestUpdate(): HasOne
    {
        return $this->hasOne(IncidentUpdate::class)->latestOfMany();
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    /** Recompute the status of every component this incident touches. */
    public function refreshComponentStatuses(): void
    {
        $this->components()->get()->each->refreshStatus();
    }
}
