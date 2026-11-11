<?php

namespace App\Mcp\Tools;

use App\Mcp\McpPayloads;
use App\Models\Component;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List components with their id, group, public status, monitored (Zabbix) status and any manual override. Use the ids with the other tools.')]
class ListComponents extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate(['include_disabled' => ['sometimes', 'boolean']]);

        $components = Component::query()
            ->when(! ($validated['include_disabled'] ?? false), fn ($q) => $q->enabled())
            ->with('group')->orderBy('order')->orderBy('name')->get();

        return Response::structured([
            'data' => $components->map(fn ($c) => McpPayloads::component($c))->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_disabled' => $schema->boolean()->description('Also list components hidden from the public page.')->default(false),
        ];
    }
}
