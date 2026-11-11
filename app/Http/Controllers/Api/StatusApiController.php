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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only public JSON API. */
class StatusApiController extends Controller
{
    public function status(StatusPageData $data): JsonResponse
    {
        $components = Component::query()->enabled()->with('group')->orderBy('order')->get();
        $overall = ComponentStatus::worst($components->pluck('status'));

        return response()->json([
            'name' => Setting::get('site_name'),
            'status' => $overall->value,
            'status_label' => $overall->getLabel(),
            'stale' => $data->staleness() !== null,
            'components' => $components->map(fn ($c) => Payloads::component($c))->values(),
            'active_incidents' => Incident::query()->visible()->unresolved()->with(['components', 'updates'])->get()
                ->map(fn ($i) => Payloads::incident($i))->values(),
            'maintenances' => Maintenance::query()->visible()->upcoming()->with('components')->get()
                ->map(fn ($m) => Payloads::maintenance($m))->values(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    public function components(): JsonResponse
    {
        return response()->json([
            'data' => Component::query()->enabled()->with('group')->orderBy('order')->get()
                ->map(fn ($c) => Payloads::component($c))->values(),
        ]);
    }

    public function incidents(Request $request): JsonResponse
    {
        $page = Incident::query()->visible()->with(['components', 'updates'])->latest('started_at')
            ->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn ($i) => Payloads::incident($i))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function incident(Incident $incident): JsonResponse
    {
        abort_unless($incident->visible, 404);

        return response()->json(['data' => Payloads::incident($incident->load(['components', 'updates']))]);
    }

    public function maintenances(): JsonResponse
    {
        return response()->json([
            'data' => Maintenance::query()->visible()->upcoming()->with('components')->get()
                ->map(fn ($m) => Payloads::maintenance($m))->values(),
        ]);
    }
}
