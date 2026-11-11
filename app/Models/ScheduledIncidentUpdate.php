<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An incident update written now and posted automatically at publish_at. */
#[Fillable(['incident_id', 'user_id', 'status', 'message', 'publish_at'])]
class ScheduledIncidentUpdate extends Model
{
    use Auditable;

    protected function casts(): array
    {
        return ['status' => IncidentStatus::class, 'publish_at' => 'datetime'];
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditLabel(): string
    {
        return ($this->incident?->title ?? 'Incident').' – scheduled '.$this->status->getLabel();
    }
}
