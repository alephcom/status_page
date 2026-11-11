<?php

namespace App\Mcp\Tools;

use App\Mcp\McpPayloads;
use App\Models\Maintenance;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[Description('List maintenance windows, including hidden ones. By default only scheduled and in-progress windows; set include_past for the last 30 days as well.')]
class ListMaintenances extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate(['include_past' => ['sometimes', 'boolean']]);

        $query = ($validated['include_past'] ?? false)
            ? Maintenance::query()->where('scheduled_end', '>', now()->subDays(30))->orderBy('scheduled_start')
            : Maintenance::query()->upcoming();

        return Response::structured([
            'data' => $query->with('components')->get()->map(fn ($m) => McpPayloads::maintenance($m))->values()->all(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'include_past' => $schema->boolean()->default(false),
        ];
    }
}
