<?php

namespace App\Mcp\Tools;

use App\Models\Maintenance;
use App\Services\MaintenanceManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[Description('Delete a maintenance window entirely (e.g. it was called off). Affected components return to their monitored status. Subscribers are not notified. To end a running window but keep its record, use update-maintenance instead.')]
class CancelMaintenance extends Tool
{
    public function handle(Request $request, MaintenanceManager $manager): Response
    {
        $v = $request->validate(['maintenance_id' => ['required', 'integer', 'exists:maintenances,id']]);

        $maintenance = Maintenance::query()->findOrFail($v['maintenance_id']);
        $manager->deleting($maintenance);
        $maintenance->delete();

        return Response::text("Maintenance #{$v['maintenance_id']} \"{$maintenance->title}\" was deleted.");
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'maintenance_id' => $schema->integer()->required(),
        ];
    }
}
