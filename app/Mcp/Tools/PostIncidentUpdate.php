<?php

namespace App\Mcp\Tools;

use App\Enums\IncidentStatus;
use App\Mcp\McpPayloads;
use App\Models\Incident;
use App\Services\IncidentManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
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
#[Description('Add an update to an incident\'s timeline and move it to the given status. Use status "resolved" to resolve it (affected components then return to their monitored status). Notifies subscribers if the incident is visible.')]
class PostIncidentUpdate extends Tool
{
    public function handle(Request $request, IncidentManager $incidents): ResponseFactory
    {
        $v = $request->validate([
            'incident_id' => ['required', 'integer', 'exists:incidents,id'],
            'status' => ['required', Rule::enum(IncidentStatus::class)],
            'message' => ['required', 'string'],
        ]);

        $incident = Incident::query()->findOrFail($v['incident_id']);
        $incidents->postUpdate($incident, IncidentStatus::from($v['status']), $v['message'], $request->user());

        return Response::structured(McpPayloads::incident($incident->fresh()->load(['components', 'updates'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'incident_id' => $schema->integer()->required(),
            'status' => $schema->string()->enum(array_column(IncidentStatus::cases(), 'value'))->required(),
            'message' => $schema->string()->description('Public update text (markdown).')->required(),
        ];
    }
}
