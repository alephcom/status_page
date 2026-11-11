<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\ComponentSource;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/** Everything the public page needs, gathered in a fixed number of queries. */
class StatusPageData
{
    public function __construct(private readonly UptimeCalculator $uptime) {}

    /** @return array<string, mixed> */
    public function build(): array
    {
        $days = (int) config('statuspage.uptime_days');
        $since = CarbonImmutable::today()->subDays($days - 1)->toDateString();

        $components = Component::query()->enabled()
            ->with(['group', 'dailyStatus' => fn ($q) => $q->where('date', '>=', $since)])
            ->orderBy('order')->orderBy('name')
            ->get();

        $components->each(function (Component $c) use ($days) {
            $c->setAttribute('uptime_history', $this->uptime->history($c, $days));
            $c->setAttribute('uptime_percent', $this->uptime->overallPercent($c->uptime_history));
        });

        $groups = ComponentGroup::query()->orderBy('order')->orderBy('name')->get()
            ->map(function (ComponentGroup $g) use ($components) {
                $g->setRelation('components', $components->where('component_group_id', $g->id)->values());

                return $g;
            })
            ->filter(fn (ComponentGroup $g) => $g->components->isNotEmpty())
            ->values();

        $historyDays = (int) Setting::get('history_days');

        return [
            'siteName' => Setting::get('site_name'),
            'about' => Setting::get('site_about'),
            'externalLinks' => Setting::get('external_links') ?: [],
            'overall' => ComponentStatus::worst($components->pluck('status')),
            'components' => $components,
            'groups' => $groups,
            'ungrouped' => $components->whereNull('component_group_id')->values(),
            'activeIncidents' => Incident::query()->visible()->unresolved()
                ->with(['components', 'updates'])->latest('started_at')->get(),
            'maintenances' => Maintenance::query()->visible()->upcoming()->with('components')->get(),
            'recentIncidents' => Incident::query()->visible()->whereNotNull('resolved_at')
                ->where('resolved_at', '>=', now()->subDays($historyDays))
                ->with(['components', 'updates'])->latest('started_at')->get(),
            'historyDays' => $historyDays,
            'uptimeDays' => $days,
            'stale' => $this->staleness(),
            'generatedAt' => now(),
        ];
    }

    /**
     * Null when monitoring data is fresh (or no component is monitored),
     * otherwise the time of the last good poll (which may itself be null).
     *
     * @return array{last_ok: ?CarbonImmutable}|null
     */
    public function staleness(): ?array
    {
        if (! ComponentSource::query()->exists()) {
            return null;
        }

        $lastOk = Setting::get('zabbix_last_ok_at');
        $lastOk = $lastOk ? CarbonImmutable::parse($lastOk) : null;

        if ($lastOk && $lastOk->greaterThan(now()->subSeconds((int) config('statuspage.stale_after')))) {
            return null;
        }

        return ['last_ok' => $lastOk];
    }

    /** @return Collection<int, Incident> */
    public function history(int $perPage = 20)
    {
        return Incident::query()->visible()->with(['components', 'updates'])->latest('started_at')->paginate($perPage);
    }
}
