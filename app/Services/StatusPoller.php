<?php

namespace App\Services;

use App\Ai\IncidentWriter;
use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Jobs\DraftAutoIncidentText;
use App\Models\Component;
use App\Models\ComponentSource;
use App\Models\Incident;
use App\Models\Setting;
use App\StatusSources\Problem;
use App\StatusSources\SourceResult;
use App\StatusSources\StatusSourceManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One polling pass: ask every source driver for its results, mirror the
 * problems locally and recompute component statuses.
 *
 * If a driver fails, components fed by it keep their last monitored status;
 * the failure is recorded so the public page can say the data is stale.
 * Manual overrides, maintenance windows and incidents are still applied.
 */
class StatusPoller
{
    public function __construct(
        private readonly StatusSourceManager $sources,
        private readonly IncidentManager $incidents,
    ) {}

    /**
     * @return array{ok: bool, changed: int, errors: list<string>}
     */
    public function poll(): array
    {
        $components = Component::query()->enabled()->with('sources')->get();
        $errors = [];
        $failedDrivers = [];
        /** @var array<int, SourceResult> $results */
        $results = [];

        $components->flatMap->sources
            ->groupBy('driver')
            ->each(function ($sources, string $driver) use (&$results, &$errors, &$failedDrivers) {
                try {
                    $results += $this->sources->driver($driver)->resolve($sources);
                } catch (Throwable $e) {
                    $failedDrivers[] = $driver;
                    $errors[] = "{$driver}: {$e->getMessage()}";
                    Log::warning('Status source failed', ['driver' => $driver, 'error' => $e->getMessage()]);
                }
            });

        $changed = 0;

        foreach ($components as $component) {
            $usable = $component->sources->reject(fn (ComponentSource $s) => in_array($s->driver, $failedDrivers, true));

            // Only touch the monitored status when every source answered;
            // otherwise a partial picture could flip a component to green.
            if ($usable->count() === $component->sources->count()) {
                $this->applyResults($component, $component->sources->map(fn ($s) => $results[$s->id] ?? null)->filter()->all());
            }

            if ($component->refreshStatus()) {
                $changed++;
                $this->maybeOpenIncident($component);
            }
        }

        if ($errors === []) {
            $this->checkRecoveries();
        }

        $now = now();

        if ($errors === []) {
            Setting::putMany(['zabbix_last_ok_at' => $now->toIso8601String(), 'zabbix_last_error' => null]);
        } else {
            Setting::putMany(['zabbix_last_error' => implode('; ', $errors), 'zabbix_last_error_at' => $now->toIso8601String()]);
        }

        Setting::put('last_poll_at', $now->toIso8601String());

        if (! Setting::get('first_poll_at')) {
            Setting::put('first_poll_at', $now->toIso8601String());
        }

        return ['ok' => $errors === [], 'changed' => $changed, 'errors' => $errors];
    }

    /**
     * @param  list<SourceResult>  $results
     */
    private function applyResults(Component $component, array $results): void
    {
        /** @var list<Problem> $problems */
        $problems = array_merge(...array_map(fn (SourceResult $r) => $r->problems, $results ?: [new SourceResult(ComponentStatus::Operational)]));
        $status = ComponentStatus::worst(array_map(fn (SourceResult $r) => $r->status, $results));

        DB::transaction(function () use ($component, $problems, $status) {
            $activeIds = [];

            foreach ($problems as $problem) {
                $activeIds[] = $problem->eventid;
                $row = $component->problems()->firstOrNew(['eventid' => $problem->eventid]);
                $reopened = $row->exists && $row->resolved_at !== null;
                $row->fill([
                    'triggerid' => $problem->triggerid,
                    'name' => mb_substr($problem->name, 0, 255),
                    'severity' => $problem->severity,
                    'resolved_at' => null,
                ]);
                // Some sources only know "now" as the start; keep the first sighting.
                if (! $row->exists || $reopened || $problem->startedAt->lessThan($row->started_at)) {
                    $row->started_at = $problem->startedAt;
                }
                $row->save();
            }

            $component->problems()
                ->whereNull('resolved_at')
                ->whereNotIn('eventid', $activeIds)
                ->update(['resolved_at' => now()]);

            if ($component->auto_status !== $status) {
                $component->forceFill(['auto_status' => $status])->saveQuietly();
            }
        });
    }

    /**
     * Optionally open a hidden draft incident when a component goes into a
     * major outage, so staff only have to review and publish it. Raw trigger
     * names are never published automatically.
     */
    private function maybeOpenIncident(Component $component): void
    {
        if (! Setting::get('auto_incidents') || $component->status !== ComponentStatus::MajorOutage) {
            return;
        }

        $alreadyOpen = $component->incidents()->unresolved()->exists();

        if ($alreadyOpen) {
            return;
        }

        $placeholder = 'Monitoring detected a problem. We are investigating.';

        $incident = $this->incidents->create(
            [
                'title' => "{$component->name} is unavailable",
                'status' => IncidentStatus::Investigating,
                'impact' => IncidentImpact::Major,
                'visible' => false,
                'auto_created' => true,
                'zabbix_eventid' => $component->activeProblems()->value('eventid'),
                'started_at' => now(),
            ],
            [$component->id],
            $placeholder,
        );

        if (Setting::get('ai_drafts') && app(IncidentWriter::class)->enabled()) {
            DraftAutoIncidentText::dispatch($incident, $placeholder);
        }
    }

    /**
     * For incidents that monitoring opened: once every component is healthy
     * again, resolve hidden drafts or flag the incident for staff. If the
     * problem comes back, the flag is cleared.
     */
    private function checkRecoveries(): void
    {
        $resolveDrafts = (bool) Setting::get('auto_resolve_recovered_drafts');

        Incident::query()->unresolved()->where('auto_created', true)
            ->with(['components' => fn ($q) => $q->withCount('activeProblems')])
            ->get()
            ->each(function (Incident $incident) use ($resolveDrafts): void {
                $healthy = $incident->components->isNotEmpty() && $incident->components->every(
                    fn (Component $c) => $c->auto_status === ComponentStatus::Operational && $c->active_problems_count === 0,
                );

                if ($healthy) {
                    $this->incidents->monitoringRecovered($incident, $resolveDrafts);
                } elseif ($incident->recovered_at !== null) {
                    $incident->forceFill(['recovered_at' => null])->saveQuietly();
                }
            });
    }
}
