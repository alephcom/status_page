<?php

namespace App\Mcp\Tools;

use App\Enums\IncidentImpact;
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
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;

#[IsIdempotent]
#[IsOpenWorld]
#[Description('Edit an incident\'s title, impact, affected components or forced component status, or publish/hide it. Only the fields given change. Setting visible=true on a draft publishes it and notifies subscribers. To change the incident status, use post-incident-update.')]
class UpdateIncident extends Tool
{
    public function handle(Request $request, IncidentManager $incidents): ResponseFactory
    {
        $v = $request->validate([
            'incident_id' => ['required', 'integer', 'exists:incidents,id'],
            'title' => ['sometimes', 'string', 'max:255'],
            'impact' => ['sometimes', Rule::enum(IncidentImpact::class)],
            'visible' => ['sometimes', 'boolean'],
            'component_ids' => ['sometimes', 'array'],
            'component_ids.*' => ['integer', 'distinct', 'exists:components,id'],
            'component_status' => ['sometimes', 'string', Rule::in(['none', 'degraded', 'partial_outage', 'major_outage', 'maintenance'])],
        ]);

        $incident = Incident::query()->findOrFail($v['incident_id']);
        $wasVisible = $incident->visible;
        $previous = $incident->components()->pluck('components.id')->all();

        $incident->fill(array_intersect_key($v, array_flip(['title', 'impact', 'visible'])));
        if (array_key_exists('component_status', $v)) {
            $incident->component_status = $v['component_status'] === 'none' ? null : $v['component_status'];
        }
        $incident->save();

        if (array_key_exists('component_ids', $v)) {
            $incident->components()->sync(array_map('intval', $v['component_ids']));
        }

        $incidents->edited($incident, $wasVisible, $previous);

        return Response::structured(McpPayloads::incident($incident->fresh()->load(['components', 'updates'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'incident_id' => $schema->integer()->required(),
            'title' => $schema->string(),
            'impact' => $schema->string()->enum(array_column(IncidentImpact::cases(), 'value')),
            'visible' => $schema->boolean()->description('true publishes (and notifies if it was hidden); false hides it from the public page.'),
            'component_ids' => $schema->array()->items($schema->integer())->description('Replaces the full list of affected components.'),
            'component_status' => $schema->string()->enum(['none', 'degraded', 'partial_outage', 'major_outage', 'maintenance'])
                ->description('"none" stops forcing a status on the components.'),
        ];
    }
}
