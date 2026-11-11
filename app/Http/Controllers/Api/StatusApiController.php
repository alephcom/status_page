<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComponentStatus;
use App\Http\Controllers\Controller;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Services\StatusPageData;
use App\Support\Payloads;
use App\Support\Visibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only public JSON API. Private components only show to allowed networks. */
class StatusApiController extends Controller
{
    public function status(Request $request, StatusPageData $data): JsonResponse
    {
        $private = Visibility::canSeePrivate($request);
        $components = $this->visibleComponents($private);
        $overall = ComponentStatus::worst($components->pluck('status'));

        return response()->json([
            'name' => Setting::get('site_name'),
            'status' => $overall->value,
            'status_label' => $overall->getLabel(),
            'stale' => $data->staleness() !== null,
            'components' => $components->map(fn ($c) => Payloads::component($c))->values(),
            'active_incidents' => Visibility::touchingPublic(Incident::query()->visible()->unresolved(), $private)
                ->with(['components', 'updates'])->get()
                ->map(fn ($i) => Payloads::incident(Visibility::scrub($i, $private)))->values(),
            'maintenances' => Visibility::touchingPublic(Maintenance::query()->visible()->upcoming(), $private)->with('components')->get()
                ->map(fn ($m) => Payloads::maintenance(Visibility::scrub($m, $private)))->values(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function components(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->visibleComponents(Visibility::canSeePrivate($request))->map(fn ($c) => Payloads::component($c))->values(),
        ]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $private = Visibility::canSeePrivate($request);
        $page = Visibility::touchingPublic(Incident::query()->visible(), $private)->with(['components', 'updates'])->latest('started_at')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn ($i) => Payloads::incident(Visibility::scrub($i, $private)))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function incident(Request $request, Incident $incident): JsonResponse
    {
        $private = Visibility::canSeePrivate($request);
        $incident->load(['components', 'updates']);

        abort_unless(Visibility::allowsIncident($incident, $private), 404);

        return response()->json(['data' => Payloads::incident(Visibility::scrub($incident, $private))]);
    }

    public function maintenances(Request $request): JsonResponse
    {
        $private = Visibility::canSeePrivate($request);

        return response()->json([
            'data' => Visibility::touchingPublic(Maintenance::query()->visible()->upcoming(), $private)->with('components')->get()
                ->map(fn ($m) => Payloads::maintenance(Visibility::scrub($m, $private)))->values(),
        ]);
    }

    private function visibleComponents(bool $private)
    {
        return Visibility::components(Component::query()->enabled(), $private)->with('group')->orderBy('order')->get();
    }
}
