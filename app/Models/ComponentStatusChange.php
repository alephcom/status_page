<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['component_id', 'from_status', 'status', 'changed_at'])]
class ComponentStatusChange extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'from_status' => ComponentStatus::class,
            'status' => ComponentStatus::class,
            'changed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
