<?php

namespace App\Http\Controllers;

use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Services\StatusPageData;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

class StatusController extends Controller
{
    public function index(Request $request, StatusPageData $data): View
    {
        $view = in_array($request->query('view'), ['compact', 'micro'], true) ? $request->query('view') : 'normal';

        return view('status.index', $data->build() + ['view' => $view]);
    }

    public function incident(Incident $incident): View
    {
        abort_unless($incident->visible, 404);

        return view('status.incident', [
            'siteName' => Setting::get('site_name'),
            'incident' => $incident->load(['components', 'updates']),
        ]);
    }

    public function history(StatusPageData $data): View
    {
        return view('status.history', [
            'siteName' => Setting::get('site_name'),
            'incidents' => $data->history(),
            'maintenances' => Maintenance::query()->visible()->where('scheduled_end', '<=', now())
                ->latest('scheduled_start')->limit(20)->get(),
        ]);
    }

    public function feed(): Response
    {
        $incidents = Incident::query()->visible()->with(['components', 'updates'])->latest('updated_at')->limit(50)->get();
        $maintenances = Maintenance::query()->visible()->latest('updated_at')->limit(20)->get();

        $entries = $incidents->map(fn (Incident $i) => [
            'id' => route('incidents.show', $i),
            'title' => "[{$i->status->getLabel()}] {$i->title}",
            'url' => route('incidents.show', $i),
            'updated' => $i->updated_at,
            'content' => $i->updates->map(fn ($u) => "<p><strong>{$u->status->getLabel()}</strong> ({$u->created_at->toIso8601String()}): ".e($u->message).'</p>')->join(''),
        ])->concat($maintenances->map(fn (Maintenance $m) => [
            'id' => route('status').'#maintenance-'.$m->id,
            'title' => "[Maintenance] {$m->title}",
            'url' => route('status'),
            'updated' => $m->updated_at,
            'content' => '<p>'.e($m->scheduled_start->toIso8601String().' – '.$m->scheduled_end->toIso8601String()).'</p><p>'.e((string) $m->message).'</p>',
        ]))->sortByDesc('updated')->values();

        return response()
            ->view('status.feed', ['siteName' => Setting::get('site_name'), 'entries' => $entries], 200)
            ->header('Content-Type', 'application/atom+xml; charset=UTF-8');
    }
}
