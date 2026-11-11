<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'message', 'scheduled_start', 'scheduled_end', 'visible'])]
class Maintenance extends Model
{
    use HasFactory;

    protected $attributes = ['visible' => true, 'notified' => false];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'visible' => 'boolean',
            'notified' => 'boolean',
        ];
    }

    /** @param Builder<Maintenance> $query */
    public function scopeVisible(Builder $query): void
    {
        $query->where('visible', true);
    }

    /** Scheduled or in progress. @param Builder<Maintenance> $query */
    public function scopeUpcoming(Builder $query): void
    {
        $query->where('scheduled_end', '>', now())->orderBy('scheduled_start');
    }

    /** @return BelongsToMany<Component, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Component::class);
    }

    public function statusAt(?CarbonInterface $now = null): MaintenanceStatus
    {
        $now ??= now();

        return match (true) {
            $now->lessThan($this->scheduled_start) => MaintenanceStatus::Scheduled,
            $now->lessThan($this->scheduled_end) => MaintenanceStatus::InProgress,
            default => MaintenanceStatus::Completed,
        };
    }

    public function getStatusAttribute(): MaintenanceStatus
    {
        return $this->statusAt();
    }
}
