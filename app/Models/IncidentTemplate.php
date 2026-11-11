<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use App\Enums\IncidentImpact;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** A reusable starting point for recurring problems, e.g. "Database failover". */
#[Fillable(['name', 'title', 'message', 'impact', 'component_status', 'component_ids'])]
class IncidentTemplate extends Model
{
    use Auditable;

    protected $attributes = ['impact' => 'minor'];

    protected function casts(): array
    {
        return [
            'impact' => IncidentImpact::class,
            'component_status' => ComponentStatus::class,
            'component_ids' => 'array',
        ];
    }

    /** @return list<int> ids of components that still exist */
    public function existingComponentIds(): array
    {
        $ids = array_map('intval', $this->component_ids ?? []);

        return $ids === [] ? [] : Component::query()->whereIn('id', $ids)->pluck('id')->all();
    }

    public function auditLabel(): string
    {
        return $this->name;
    }
}
