<?php

namespace App\Services;

use App\Events\MaintenancePhaseReached;
use App\Events\MaintenanceScheduled;
use App\Models\Component;
use App\Models\Maintenance;
use App\Models\Setting;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class MaintenanceManager
{
    /**
     * Call after a maintenance window was created or edited.
     *
     * @param  list<int>  $previousComponentIds
     */
    public function saved(Maintenance $maintenance, array $previousComponentIds = []): void
    {
        $ids = array_unique([...$previousComponentIds, ...$maintenance->components()->pluck('components.id')->all()]);
        Component::query()->whereIn('id', $ids)->get()->each(function (Component $c): void {
            $c->refreshStatus();
        });

        if ($maintenance->visible && ! $maintenance->notified && $maintenance->scheduled_end->isFuture()) {
            $now = now();
            $reminder = (int) Setting::get('maintenance_reminder_minutes');

            // The "scheduled" message already covers what a reminder or a
            // "started" message would say if they followed straight after.
            $maintenance->forceFill(array_filter([
                'notified' => true,
                'reminder_sent_at' => $reminder > 0 && $maintenance->scheduled_start->lessThanOrEqualTo($now->copy()->addMinutes($reminder)) ? $now : null,
                'started_notified_at' => $maintenance->scheduled_start->lessThanOrEqualTo($now) ? $now : null,
            ], fn ($v) => $v !== null))->saveQuietly();

            MaintenanceScheduled::dispatch($maintenance);
        }
    }

    public function deleting(Maintenance $maintenance): void
    {
        $components = $maintenance->components()->get();
        $maintenance->components()->detach();
        $components->each(function (Component $c): void {
            $c->refreshStatus();
        });
    }

    /**
     * Run every minute: send reminders, announce starts and ends, refresh
     * affected components at the boundaries, and roll recurring windows on.
     */
    public function tick(?CarbonInterface $now = null): void
    {
        $now ??= now();

        $this->sendReminders($now);
        $this->announce($now);
        $this->rollRecurring($now);
    }

    private function sendReminders(CarbonInterface $now): void
    {
        $minutes = (int) Setting::get('maintenance_reminder_minutes');

        if ($minutes <= 0) {
            return;
        }

        Maintenance::query()->visible()->where('notified', true)->whereNull('reminder_sent_at')
            ->where('scheduled_start', '>', $now)
            ->where('scheduled_start', '<=', $now->copy()->addMinutes($minutes))
            ->get()
            ->each(fn (Maintenance $m) => $this->fire($m, 'reminder_sent_at', MaintenancePhaseReached::REMINDER, $now));
    }

    private function announce(CarbonInterface $now): void
    {
        Maintenance::query()->whereNull('started_notified_at')
            ->where('scheduled_start', '<=', $now)->where('scheduled_end', '>', $now)
            ->get()
            ->each(function (Maintenance $m) use ($now) {
                $this->refreshComponents($m, $now);
                $this->fire($m, 'started_notified_at', MaintenancePhaseReached::STARTED, $now);
            });

        // Only windows we saw start: editing an old window into the past
        // should not announce that it "completed".
        Maintenance::query()->whereNotNull('started_notified_at')->whereNull('completed_notified_at')
            ->where('scheduled_end', '<=', $now)
            ->get()
            ->each(function (Maintenance $m) use ($now) {
                $this->refreshComponents($m, $now);
                $this->fire($m, 'completed_notified_at', MaintenancePhaseReached::COMPLETED, $now);
            });
    }

    private function fire(Maintenance $m, string $column, string $phase, CarbonInterface $now): void
    {
        // Claim the notification so overlapping schedulers send it once.
        $claimed = Maintenance::query()->whereKey($m->id)->whereNull($column)->update([$column => $now]);

        if ($claimed && $m->visible) {
            MaintenancePhaseReached::dispatch($m->fresh(), $phase);
        }
    }

    private function refreshComponents(Maintenance $m, CarbonInterface $now): void
    {
        $m->components()->get()->each(function (Component $c) use ($now): void {
            $c->refreshStatus($now);
        });
    }

    /**
     * When a recurring window ends, create the next occurrence (skipping any
     * already in the past) and hand the recurrence on to it.
     */
    private function rollRecurring(CarbonInterface $now): void
    {
        Maintenance::query()->whereNotNull('recurrence')->where('scheduled_end', '<=', $now)
            ->with('components')
            ->get()
            ->each(function (Maintenance $m) use ($now) {
                $next = $this->nextOccurrence($m, $now);

                DB::transaction(function () use ($m, $next) {
                    $m->forceFill(['recurrence' => null])->saveQuietly();

                    if ($next === null) {
                        return;
                    }

                    $copy = $m->replicate(['notified', 'reminder_sent_at', 'started_notified_at', 'completed_notified_at']);
                    $copy->fill([
                        'scheduled_start' => $next[0],
                        'scheduled_end' => $next[1],
                        'recurrence' => $next[2],
                    ]);
                    // Subscribers were told about the series when it was
                    // scheduled; occurrences get reminders and start/end notices.
                    $copy->notified = true;
                    $copy->save();
                    $copy->components()->sync($m->components->pluck('id'));
                });
            });
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface, 2: string}|null
     */
    private function nextOccurrence(Maintenance $m, CarbonInterface $now): ?array
    {
        $recurrence = $m->recurrence;
        $start = $m->scheduled_start;
        $end = $m->scheduled_end;

        do {
            $start = $recurrence->next($start);
            $end = $recurrence->next($end);
        } while ($end->lessThanOrEqualTo($now));

        if ($m->recurrence_until && $start->greaterThan($m->recurrence_until)) {
            return null;
        }

        return [$start, $end, $recurrence->value];
    }
}
