<?php

namespace App\Models;

use App\Enums\MaintenanceStatus;
use App\Enums\Recurrence;
use App\Models\Concerns\Auditable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[Fillable(['title', 'message', 'scheduled_start', 'scheduled_end', 'visible', 'recurrence', 'recurrence_until'])]
class Maintenance extends Model
{
    use Auditable, HasFactory;

    protected $attributes = ['visible' => true, 'notified' => false];

    protected function casts(): array
    {
        return [
            'scheduled_start' => 'datetime',
            'scheduled_end' => 'datetime',
            'visible' => 'boolean',
            'notified' => 'boolean',
            'reminder_sent_at' => 'datetime',
            'started_notified_at' => 'datetime',
            'completed_notified_at' => 'datetime',
            'recurrence' => Recurrence::class,
            'recurrence_until' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Moving a window into the future re-arms its lifecycle notifications.
        static::saving(function (Maintenance $m) {
            if ($m->exists && $m->isDirty('scheduled_start') && $m->scheduled_start->isFuture()) {
                $m->reminder_sent_at = null;
                $m->started_notified_at = null;
            }

            if ($m->exists && $m->isDirty('scheduled_end') && $m->scheduled_end->isFuture()) {
                $m->completed_notified_at = null;
            }
        });
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

    public function auditLabel(): string
    {
        return $this->title;
    }
}
