<?php

namespace App\Mcp\Tools;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Mcp\McpPayloads;
use App\Models\IncidentTemplate;
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
#[Description('Open an incident with a first update. If visible is true it appears on the public page immediately and subscribers are e-mailed and webhooks fired; use visible=false to save a draft and publish later with update-incident.')]
class CreateIncident extends Tool
{
    public function handle(Request $request, IncidentManager $incidents): ResponseFactory
    {
        $v = $request->validate([
            'template_id' => ['sometimes', 'integer', 'exists:incident_templates,id'],
            'title' => [Rule::requiredIf(! $request->get('template_id')), 'string', 'max:255'],
            'message' => [Rule::requiredIf(! $request->get('template_id')), 'string'],
            'visible' => ['required', 'boolean'],
            'status' => ['sometimes', Rule::enum(IncidentStatus::class)->except(IncidentStatus::Resolved)],
            'impact' => ['sometimes', Rule::enum(IncidentImpact::class)],
            'component_ids' => ['sometimes', 'array'],
            'component_ids.*' => ['integer', 'distinct', 'exists:components,id'],
            'component_status' => ['sometimes', 'nullable', Rule::enum(ComponentStatus::class)->except(ComponentStatus::Operational)],
        ]);

        $template = isset($v['template_id']) ? IncidentTemplate::query()->find($v['template_id']) : null;

        $incident = $incidents->create([
            'title' => $v['title'] ?? $template->title,
            'visible' => $v['visible'],
            'status' => $v['status'] ?? IncidentStatus::Investigating->value,
            'impact' => $v['impact'] ?? $template?->impact->value ?? IncidentImpact::Minor->value,
            'component_status' => array_key_exists('component_status', $v) ? $v['component_status'] : $template?->component_status?->value,
        ], array_map('intval', $v['component_ids'] ?? $template?->existingComponentIds() ?? []), $v['message'] ?? $template->message, $request->user());

        return Response::structured(McpPayloads::incident($incident->load(['components', 'updates'])));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->integer()->description('Optional template from list-incident-templates; its title, message, impact and components are used unless given here.'),
            'title' => $schema->string()->description('Short public headline, e.g. "Login failures on the portal". Required without template_id.'),
            'message' => $schema->string()->description('First public update (markdown). Required without template_id.'),
            'visible' => $schema->boolean()->description('true publishes now and notifies subscribers; false saves a hidden draft.')->required(),
            'status' => $schema->string()->enum(['investigating', 'identified', 'monitoring'])->default('investigating'),
            'impact' => $schema->string()->enum(array_column(IncidentImpact::cases(), 'value'))->default('minor'),
            'component_ids' => $schema->array()->items($schema->integer())->description('Affected component ids from list-components.'),
            'component_status' => $schema->string()->enum(['degraded', 'partial_outage', 'major_outage', 'maintenance'])
                ->description('Status forced on the affected components while the incident is open. Omit to leave them to monitoring.'),
        ];
    }
}
