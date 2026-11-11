<?php

namespace App\Mcp\Tools;

use App\Mcp\McpPayloads;
use App\Models\Component;
use App\Services\UptimeCalculator;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('One component in detail: current status, the active Zabbix problems driving it, open incidents and maintenance affecting it, and uptime over the last N days.')]
class GetComponent extends Tool
{
    public function handle(Request $request, UptimeCalculator $uptime): ResponseFactory
    {
        $validated = $request->validate([
            'component_id' => ['required', 'integer', 'exists:components,id'],
            'days' => ['sometimes', 'integer', 'min:1', 'max:365'],
        ]);

        $component = Component::query()->with('group')->findOrFail($validated['component_id']);
        $history = $uptime->history($component, $validated['days'] ?? (int) config('statuspage.uptime_days'));

        return Response::structured(McpPayloads::component($component) + [
            'active_problems' => $component->activeProblems()->get()->map(fn ($p) => McpPayloads::problem($p))->values()->all(),
            'open_incidents' => $component->incidents()->unresolved()->with(['components', 'updates'])->get()
                ->map(fn ($i) => McpPayloads::incident($i, withUpdates: false))->values()->all(),
            'maintenances' => $component->maintenances()->upcoming()->with('components')->get()
                ->map(fn ($m) => McpPayloads::maintenance($m))->values()->all(),
            'uptime' => [
                'days' => count($history),
                'percent' => $uptime->overallPercent($history),
                'daily' => collect($history)->map(fn ($d) => [
                    'date' => $d['date']->toDateString(),
                    'percent' => $d['row'] ? $uptime->overallPercent([$d]) : null,
                ])->all(),
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'component_id' => $schema->integer()->description('Component id from list-components.')->required(),
            'days' => $schema->integer()->min(1)->max(365)->description('Uptime window in days. Defaults to the page setting (usually 90).'),
        ];
    }
}
