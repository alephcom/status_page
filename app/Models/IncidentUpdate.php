<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['incident_id', 'user_id', 'status', 'message'])]
class IncidentUpdate extends Model
{
    use Auditable;

    protected $touches = ['incident'];

    protected function casts(): array
    {
        return ['status' => IncidentStatus::class];
    }

    /** @return BelongsTo<Incident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }

    public function auditLabel(): string
    {
        return ($this->incident?->title ?? 'Incident').' – '.$this->status->getLabel();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
