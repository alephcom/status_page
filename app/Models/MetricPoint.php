<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['component_metric_id', 'recorded_at', 'value'])]
class MetricPoint extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['recorded_at' => 'datetime', 'value' => 'float'];
    }

    /** @return BelongsTo<ComponentMetric, $this> */
    public function metric(): BelongsTo
    {
        return $this->belongsTo(ComponentMetric::class, 'component_metric_id');
    }
}
