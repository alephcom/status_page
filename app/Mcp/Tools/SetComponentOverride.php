<?php

namespace App\Mcp\Tools;

use App\Enums\ComponentStatus;
use App\Mcp\Concerns\ChecksRole;
use App\Mcp\McpPayloads;
use App\Models\Component;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsIdempotent]
#[IsOpenWorld]
#[Description('Admin only. Force a component\'s public status regardless of monitoring, maintenance and incidents, optionally until a time; or pass status "clear" to go back to automatic. Prefer an incident when there is a real problem. A status change fires webhooks.')]
class SetComponentOverride extends Tool
{
    use ChecksRole;

    public function handle(Request $request): ResponseFactory
    {
        $this->requireAdmin($request);

        $v = $request->validate([
            'component_id' => ['required', 'integer', 'exists:components,id'],
            'status' => ['required', 'string', Rule::in(['clear', ...array_column(ComponentStatus::cases(), 'value')])],
            'until' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $component = Component::query()->with('group')->findOrFail($v['component_id']);
        $clear = $v['status'] === 'clear';

        $component->update([
            'manual_status' => $clear ? null : $v['status'],
            'manual_status_until' => $clear ? null : ($v['until'] ?? null),
        ]);

        return Response::structured(McpPayloads::component($component->fresh('group')));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'component_id' => $schema->integer()->required(),
            'status' => $schema->string()->enum(['clear', ...array_column(ComponentStatus::cases(), 'value')])->required(),
            'until' => $schema->string()->format('date-time')->description('ISO 8601. Omit to keep the override until cleared.'),
        ];
    }
}
