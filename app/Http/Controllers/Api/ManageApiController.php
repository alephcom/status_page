<?php

namespace App\Http\Controllers\Api;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Enums\IncidentStatus;
use App\Enums\Recurrence;
use App\Http\Controllers\Controller;
use App\Mcp\McpPayloads;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\Maintenance;
use App\Models\User;
use App\Services\IncidentManager;
use App\Services\MaintenanceManager;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Write API for scripts and CI, using the same bearer tokens as the MCP
 * server (Settings → API tokens). Same rules as the admin panel: editors
 * manage incidents and maintenance; component overrides are admin-only.
 */
class ManageApiController extends Controller
{
    public function __construct(
        private readonly IncidentManager $incidents,
        private readonly MaintenanceManager $maintenance,
    ) {}

    public function createIncident(Request $request): JsonResponse
    {
        $v = $request->validate([
            'template_id' => ['sometimes', 'integer', 'exists:incident_templates,id'],
            'title' => [Rule::requiredIf(! $request->has('template_id')), 'string', 'max:255'],
            'message' => [Rule::requiredIf(! $request->has('template_id')), 'string'],
            'visible' => ['required', 'boolean'],
            'status' => ['sometimes', Rule::enum(IncidentStatus::class)->except(IncidentStatus::Resolved)],
            'impact' => ['sometimes', Rule::enum(IncidentImpact::class)],
            'component_ids' => ['sometimes', 'array'],
            'component_ids.*' => ['integer', 'distinct', 'exists:components,id'],
            'component_status' => ['sometimes', 'nullable', Rule::enum(ComponentStatus::class)->except(ComponentStatus::Operational)],
        ]);

        $template = isset($v['template_id']) ? IncidentTemplate::query()->find($v['template_id']) : null;

        $incident = $this->incidents->create([
            'title' => $v['title'] ?? $template->title,
            'visible' => $v['visible'],
            'status' => $v['status'] ?? IncidentStatus::Investigating->value,
            'impact' => $v['impact'] ?? $template?->impact->value ?? IncidentImpact::Minor->value,
            'component_status' => array_key_exists('component_status', $v) ? $v['component_status'] : $template?->component_status?->value,
        ], array_map('intval', $v['component_ids'] ?? $template?->existingComponentIds() ?? []), $v['message'] ?? $template->message, $this->user($request));

        return response()->json(['data' => McpPayloads::incident($incident->load(['components', 'updates']))], 201);
    }

    public function updateIncident(Request $request, Incident $incident): JsonResponse
    {
        $v = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'impact' => ['sometimes', Rule::enum(IncidentImpact::class)],
            'visible' => ['sometimes', 'boolean'],
            'component_ids' => ['sometimes', 'array'],
            'component_ids.*' => ['integer', 'distinct', 'exists:components,id'],
            'component_status' => ['sometimes', 'nullable', Rule::enum(ComponentStatus::class)->except(ComponentStatus::Operational)],
            'postmortem' => ['sometimes', 'nullable', 'string'],
            'postmortem_published' => ['sometimes', 'boolean'],
        ]);

        $wasVisible = $incident->visible;
        $previous = $incident->components()->pluck('components.id')->all();

        $incident->fill(array_intersect_key($v, array_flip(['title', 'impact', 'visible', 'component_status', 'postmortem'])));

        if (array_key_exists('postmortem_published', $v)) {
            $incident->postmortem_published_at = $v['postmortem_published'] ? ($incident->postmortem_published_at ?? now()) : null;
        }

        $incident->save();

        if (array_key_exists('component_ids', $v)) {
            $incident->components()->sync(array_map('intval', $v['component_ids']));
        }

        $this->incidents->edited($incident, $wasVisible, $previous);

        return response()->json(['data' => McpPayloads::incident($incident->fresh()->load(['components', 'updates']))]);
    }

    public function postUpdate(Request $request, Incident $incident): JsonResponse
    {
        $v = $request->validate([
            'status' => ['required', Rule::enum(IncidentStatus::class)],
            'message' => ['required', 'string'],
            'publish_at' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        if (! empty($v['publish_at'])) {
            $scheduled = $this->incidents->scheduleUpdate($incident, IncidentStatus::from($v['status']), $v['message'], CarbonImmutable::parse($v['publish_at']), $this->user($request));

            return response()->json(['data' => [
                'scheduled_update_id' => $scheduled->id,
                'publish_at' => $scheduled->publish_at->toIso8601String(),
            ]], 202);
        }

        $this->incidents->postUpdate($incident, IncidentStatus::from($v['status']), $v['message'], $this->user($request));

        return response()->json(['data' => McpPayloads::incident($incident->fresh()->load(['components', 'updates']))], 201);
    }

    public function createMaintenance(Request $request): JsonResponse
    {
        $v = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string'],
            'scheduled_start' => ['required', 'date'],
            'scheduled_end' => ['required', 'date', 'after:scheduled_start', 'after:now'],
            'visible' => ['required', 'boolean'],
            'recurrence' => ['sometimes', 'nullable', Rule::enum(Recurrence::class)],
            'recurrence_until' => ['sometimes', 'nullable', 'date', 'after:scheduled_start'],
            'component_ids' => ['required', 'array', 'min:1'],
            'component_ids.*' => ['integer', 'distinct', 'exists:components,id'],
        ]);

        $m = DB::transaction(function () use ($v) {
            $m = Maintenance::create(collect($v)->except('component_ids')->all());
            $m->components()->sync(array_map('intval', $v['component_ids']));

            return $m;
        });

        $this->maintenance->saved($m);

        return response()->json(['data' => McpPayloads::maintenance($m->fresh()->load('components'))], 201);
    }

    public function updateMaintenance(Request $request, Maintenance $maintenance): JsonResponse
    {
        $v = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'message' => ['sometimes', 'nullable', 'string'],
            'scheduled_start' => ['sometimes', 'date'],
            'scheduled_end' => ['sometimes', 'date'],
            'visible' => ['sometimes', 'boolean'],
            'recurrence' => ['sometimes', 'nullable', Rule::enum(Recurrence::class)],
            'recurrence_until' => ['sometimes', 'nullable', 'date'],
            'component_ids' => ['sometimes', 'array', 'min:1'],
            'component_ids.*' => ['integer', 'distinct', 'exists:components,id'],
        ]);

        $previous = $maintenance->components()->pluck('components.id')->all();
        $maintenance->fill(collect($v)->except('component_ids')->all());

        Validator::make(
            ['start' => $maintenance->scheduled_start, 'end' => $maintenance->scheduled_end],
            ['end' => ['after:start']],
            ['end.after' => 'The window must end after it starts.'],
        )->validate();

        $maintenance->save();

        if (array_key_exists('component_ids', $v)) {
            $maintenance->components()->sync(array_map('intval', $v['component_ids']));
        }

        $this->maintenance->saved($maintenance, $previous);

        return response()->json(['data' => McpPayloads::maintenance($maintenance->fresh()->load('components'))]);
    }

    public function deleteMaintenance(Maintenance $maintenance): JsonResponse
    {
        $this->maintenance->deleting($maintenance);
        $maintenance->delete();

        return response()->json(null, 204);
    }

    public function setOverride(Request $request, Component $component): JsonResponse
    {
        abort_unless($this->user($request)->isAdmin(), 403, 'Only admins can override component status.');

        $v = $request->validate([
            'status' => ['required', 'string', Rule::in(['clear', ...array_column(ComponentStatus::cases(), 'value')])],
            'until' => ['sometimes', 'nullable', 'date', 'after:now'],
        ]);

        $clear = $v['status'] === 'clear';
        $component->update([
            'manual_status' => $clear ? null : $v['status'],
            'manual_status_until' => $clear ? null : ($v['until'] ?? null),
        ]);

        return response()->json(['data' => McpPayloads::component($component->fresh('group'))]);
    }

    private function user(Request $request): User
    {
        return $request->user();
    }
}
