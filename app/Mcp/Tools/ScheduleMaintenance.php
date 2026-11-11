<?php

namespace App\Mcp\Tools;

use App\Enums\Recurrence;
use App\Mcp\McpPayloads;
use App\Models\Maintenance;
use App\Services\MaintenanceManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsDestructive(false)]
#[IsOpenWorld]
#[Description('Schedule a maintenance window. Affected components show "Under maintenance" during it. If visible, subscribers are notified once when it is created.')]
class ScheduleMaintenance extends Tool
{
    public function handle(Request $request, MaintenanceManager $manager): ResponseFactory
    {
        $v = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string'],
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start', 'after:now'],
            'visible' => ['required', 'boolean'],
            'recurrence' => ['sometimes', 'nullable', Rule::enum(Recurrence::class)],
            'recurrence_until' => ['sometimes', 'nullable', 'date', 'after:scheduled_start'],
            'component_ids' => ['required', 'array', 'min:1'],
            'component_ids.*' => ['integer', 'distinct', 'exists:components,id'],
        ]);

        $maintenance = DB::transaction(function () use ($v) {
            $m = Maintenance::create(collect($v)->only(['title', 'message', 'scheduled_start', 'scheduled_end', 'visible', 'recurrence', 'recurrence_until'])->all());
            $m->components()->sync(array_map('intval', $v['component_ids']));

            return $m;
        });

        $manager->saved($maintenance);

        return Response::structured(McpPayloads::maintenance($maintenance->fresh()->load('components')));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->required(),
            'message' => $schema->string()->description('Public details (markdown).'),
            'scheduled_start' => $schema->string()->format('date-time')->description('ISO 8601, include a UTC offset.')->required(),
            'scheduled_end' => $schema->string()->format('date-time')->description('ISO 8601, after scheduled_start.')->required(),
            'visible' => $schema->boolean()->description('true shows it publicly and notifies subscribers.')->required(),
            'component_ids' => $schema->array()->items($schema->integer())->description('Affected component ids.')->required(),
            'recurrence' => $schema->string()->enum(['daily', 'weekly', 'monthly'])->description('Repeat the window: the next one is created when this one ends.'),
            'recurrence_until' => $schema->string()->format('date-time')->description('Stop repeating after this time.'),
        ];
    }
}
