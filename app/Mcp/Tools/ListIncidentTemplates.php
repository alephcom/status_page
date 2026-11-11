<?php

namespace App\Mcp\Tools;

use App\Models\IncidentTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List incident templates: presets for recurring problems. Pass a template_id to create-incident to use one.')]
class ListIncidentTemplates extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        return Response::structured([
            'data' => IncidentTemplate::query()->orderBy('name')->get()->map(fn (IncidentTemplate $t) => [
                'id' => $t->id,
                'name' => $t->name,
                'title' => $t->title,
                'message' => $t->message,
                'impact' => $t->impact->value,
                'component_status' => $t->component_status?->value,
                'component_ids' => $t->existingComponentIds(),
            ])->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
