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
#[Description('List incidents, newest first, including hidden drafts. Updates are omitted; use get-incident for the timeline.')]
class ListIncidents extends Tool
{
    public function handle(Request $request): ResponseFactory
    {
        $validated = $request->validate([
            'state' => ['sometimes', 'in:open,resolved,all'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $page = Incident::query()
            ->when(($validated['state'] ?? 'all') === 'open', fn ($q) => $q->unresolved())
            ->when(($validated['state'] ?? 'all') === 'resolved', fn ($q) => $q->whereNotNull('resolved_at'))
            ->with('components')->latest('started_at')->latest('id')
            ->paginate($validated['per_page'] ?? 20, page: $validated['page'] ?? 1);

        return Response::structured([
            'data' => collect($page->items())->map(fn ($i) => McpPayloads::incident($i, withUpdates: false))->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->string()->enum(['open', 'resolved', 'all'])->default('all'),
            'page' => $schema->integer()->min(1)->default(1),
            'per_page' => $schema->integer()->min(1)->max(100)->default(20),
        ];
    }
}
