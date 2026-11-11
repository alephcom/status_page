<?php

namespace App\Mcp\Tools;

use App\Enums\ComponentStatus;
use App\Mcp\McpPayloads;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Services\StatusPageData;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('Overall status page summary: worst component status, every enabled component, open incidents (including hidden drafts) and upcoming or in-progress maintenance.')]
class GetStatus extends Tool
{
    public function handle(Request $request, StatusPageData $data): ResponseFactory
    {
        $components = Component::query()->enabled()->with('group')->orderBy('order')->orderBy('name')->get();
        $overall = ComponentStatus::worst($components->pluck('status'));

        return Response::structured([
            'name' => Setting::get('site_name'),
            'status' => $overall->value,
            'status_label' => $overall->getLabel(),
            'stale' => $data->staleness() !== null,
            'components' => $components->map(fn ($c) => McpPayloads::component($c))->values()->all(),
            'open_incidents' => Incident::query()->unresolved()->with(['components', 'updates'])->latest('started_at')->get()
                ->map(fn ($i) => McpPayloads::incident($i))->values()->all(),
            'maintenances' => Maintenance::query()->upcoming()->with('components')->get()
                ->map(fn ($m) => McpPayloads::maintenance($m))->values()->all(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }
}
