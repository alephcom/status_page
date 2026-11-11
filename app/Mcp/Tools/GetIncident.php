<?php

namespace App\Mcp\Tools;

use App\Mcp\McpPayloads;
use App\Models\Incident;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('One incident with its full update timeline (newest first).')]
class GetIncident extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate(['incident_id' => ['required', 'integer', 'exists:incidents,id']]);

        $incident = Incident::query()->with(['components', 'updates'])->findOrFail($validated['incident_id']);

        return Response::structured(McpPayloads::incident($incident));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'incident_id' => $schema->integer()->required(),
        ];
    }
}
