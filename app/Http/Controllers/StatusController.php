<?php

namespace App\Http\Controllers;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Services\StatusPageData;
use App\Support\Badge;
use App\Support\ICalendar;
use App\Support\Visibility;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class StatusController extends Controller
{
    public function index(Request $request, StatusPageData $data): View
    {
        $view = in_array($request->query('view'), ['compact', 'micro'], true) ? $request->query('view') : 'normal';

        return view('status.index', $data->build(Visibility::canSeePrivate($request)) + ['view' => $view]);
    }

    public function incident(Request $request, Incident $incident): View
    {
        $private = Visibility::canSeePrivate($request);
        $incident->load(['components', 'updates']);

        abort_unless(Visibility::allowsIncident($incident, $private), 404);

        return view('status.incident', [
            'siteName' => Setting::get('site_name'),
            'incident' => Visibility::scrub($incident, $private),
        ]);
    }

    public function history(Request $request, StatusPageData $data): View
    {
        $private = Visibility::canSeePrivate($request);

        return view('status.history', [
            'siteName' => Setting::get('site_name'),
            'incidents' => $data->history($private),
            'maintenances' => Visibility::touchingPublic(Maintenance::query()->visible()->where('scheduled_end', '<=', now()), $private)
                ->with('components')->latest('scheduled_start')->limit(20)->get()
                ->each(fn ($m) => Visibility::scrub($m, $private)),
        ]);
    }

    /** Atom feed; ?components=1,2 limits it to those components (plus site-wide notices). */
    public function feed(Request $request): Response
    {
        $private = Visibility::canSeePrivate($request);
        $ids = $this->componentFilter($request, $private);

        $incidents = $this->forComponents(Visibility::touchingPublic(Incident::query()->visible(), $private), $ids)
            ->with(['components', 'updates'])->latest('updated_at')->limit(50)->get();
        $maintenances = $this->forComponents(Visibility::touchingPublic(Maintenance::query()->visible(), $private), $ids)
            ->latest('updated_at')->limit(20)->get();

        $entries = $incidents->map(fn (Incident $i) => [
            'id' => route('incidents.show', $i),
            'title' => "[{$i->status->getLabel()}] {$i->title}",
            'url' => route('incidents.show', $i),
            'updated' => $i->updated_at,
            'content' => $i->updates->map(fn ($u) => "<p><strong>{$u->status->getLabel()}</strong> ({$u->created_at->toIso8601String()}): ".e($u->message).'</p>')->join('')
                .($i->hasPublishedPostmortem() ? '<h3>'.e(__('Post-incident review')).'</h3><p>'.nl2br(e($i->postmortem)).'</p>' : ''),
        ])->concat($maintenances->map(fn (Maintenance $m) => [
            'id' => route('status').'#maintenance-'.$m->id,
            'title' => "[Maintenance] {$m->title}",
            'url' => route('status'),
            'updated' => $m->updated_at,
            'content' => '<p>'.e($m->scheduled_start->toIso8601String().' – '.$m->scheduled_end->toIso8601String()).'</p><p>'.e((string) $m->message).'</p>',
        ]))->sortByDesc('updated')->values();

        $title = Setting::get('site_name');
        if ($ids !== []) {
            $title .= ' – '.Component::query()->whereIn('id', $ids)->orderBy('name')->pluck('name')->join(', ');
        }

        return response()
            ->view('status.feed', ['siteName' => $title, 'entries' => $entries, 'self' => $request->fullUrl()], 200)
            ->header('Content-Type', 'application/atom+xml; charset=UTF-8');
    }

    /** iCalendar of planned maintenance, for calendar subscriptions. ?components=1,2 filters. */
    public function calendar(Request $request): Response
    {
        $private = Visibility::canSeePrivate($request);
        $ids = $this->componentFilter($request, $private);

        $maintenances = $this->forComponents(Visibility::touchingPublic(Maintenance::query()->visible(), $private), $ids)
            ->where('scheduled_end', '>=', now()->subDays(30))
            ->with('components')->orderBy('scheduled_start')->limit(500)->get()
            ->each(fn ($m) => Visibility::scrub($m, $private));

        $name = Setting::get('site_name').' – '.__('Planned maintenance');
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'statuspage';

        return response(ICalendar::render($name, $maintenances, $host), 200)
            ->header('Content-Type', 'text/calendar; charset=UTF-8')
            ->header('Content-Disposition', 'inline; filename="maintenance.ics"');
    }

    /** Shields-style SVG badge for the overall status. */
    public function badge(Request $request): Response
    {
        $statuses = Component::query()->enabled()->public()->pluck('status');

        return $this->svgBadge($request, (string) ($request->query('label') ?: __('status')), ComponentStatus::worst($statuses));
    }

    public function componentBadge(Request $request, Component $component): Response
    {
        abort_unless($component->enabled && ! $component->isPrivate(), 404);

        return $this->svgBadge($request, (string) ($request->query('label') ?: $component->name), $component->status);
    }

    /** Script that shows a small status pill on another site. */
    public function embed(): Response
    {
        return response()
            ->view('status.embed', [
                'statusUrl' => route('status'),
                'apiUrl' => url('/api/v1/status'),
                'labels' => [
                    'operational' => __('All systems operational'),
                    'degraded' => __('Some systems are degraded'),
                    'partial_outage' => __('Partial outage'),
                    'major_outage' => __('Major outage'),
                    'maintenance' => __('Maintenance in progress'),
                ],
            ], 200)
            ->header('Content-Type', 'application/javascript; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=300');
    }

    private function svgBadge(Request $request, string $label, ComponentStatus $status): Response
    {
        $text = match ($status) {
            ComponentStatus::Operational => __('operational'),
            ComponentStatus::Degraded => __('degraded'),
            ComponentStatus::PartialOutage => __('partial outage'),
            ComponentStatus::MajorOutage => __('major outage'),
            ComponentStatus::Maintenance => __('maintenance'),
        };

        return response(Badge::svg(mb_substr($label, 0, 40), $text, $status, $request->query('style') === 'flat-square'), 200)
            ->header('Content-Type', 'image/svg+xml; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=60');
    }

    /** @return list<int> */
    private function componentFilter(Request $request, bool $private): array
    {
        $raw = $request->query('components');

        if (blank($raw)) {
            return [];
        }

        $ids = array_values(array_filter(array_map('intval', is_array($raw) ? $raw : explode(',', (string) $raw))));

        return Visibility::components(Component::query()->enabled(), $private)->whereIn('id', $ids)->pluck('id')->all() ?: [0];
    }

    /**
     * @template T of Incident|Maintenance
     *
     * @param  Builder<T>  $query
     * @param  list<int>  $ids
     * @return Builder<T>
     */
    private function forComponents(Builder $query, array $ids): Builder
    {
        if ($ids === []) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('components')
            ->orWhereHas('components', fn (Builder $c) => $c->whereIn('components.id', $ids)));
    }
}
