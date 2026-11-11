<?php

namespace App\Mcp\Tools;

use App\Mcp\McpPayloads;
use App\Models\Maintenance;
use App\Services\MaintenanceManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsIdempotent]
#[IsOpenWorld]
#[Description('Edit a maintenance window: reschedule, extend or end it early, change its text or components, or show/hide it. Only the fields given change. To end one now, set scheduled_end to the current time.')]
class UpdateMaintenance extends Tool
{
    public function handle(Request $request, MaintenanceManager $manager): ResponseFactory
    {
        $v = $request->validate([
            'maintenance_id' => ['required', 'integer', 'exists:maintenances,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string'],
            'scheduled_start' => ['sometimes', 'date'],
            'scheduled_end' => ['sometimes', 'date'],
            'visible' => ['sometimes', 'boolean'],
            'component_ids' => ['sometimes', 'array', 'min:1'],
            'component_ids.*' => ['integer', 'distinct', 'exists:components,id'],
        ]);

        $maintenance = Maintenance::query()->findOrFail($v['maintenance_id']);
        $previous = $maintenance->components()->pluck('components.id')->all();

        $maintenance->fill(collect($v)->only(['title', 'message', 'scheduled_start', 'scheduled_end', 'visible'])->all());

        Validator::make(
            ['start' => $maintenance->scheduled_start, 'end' => $maintenance->scheduled_end],
            ['end' => ['after:start']],
            ['end.after' => 'The window must end after it starts.'],
        )->validate();

        $maintenance->save();

        if (array_key_exists('component_ids', $v)) {
            $maintenance->components()->sync(array_map('intval', $v['component_ids']));
        }

        $manager->saved($maintenance, $previous);

        return Response::structured(McpPayloads::maintenance($maintenance->fresh()->load('components')));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'maintenance_id' => $schema->integer()->required(),
            'title' => $schema->string(),
            'message' => $schema->string(),
            'scheduled_start' => $schema->string()->format('date-time'),
            'scheduled_end' => $schema->string()->format('date-time'),
            'visible' => $schema->boolean(),
            'component_ids' => $schema->array()->items($schema->integer())->description('Replaces the full list.'),
        ];
    }
}
