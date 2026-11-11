<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A Zabbix item (e.g. a web scenario's response time) graphed under a
 * component on the public page. Samples are stored locally in metric_points.
 */
#[Fillable(['component_id', 'name', 'host', 'item_key', 'unit', 'decimals', 'enabled', 'order'])]
class ComponentMetric extends Model
{
    use Auditable;

    protected $attributes = ['enabled' => true, 'decimals' => 0, 'order' => 0];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'decimals' => 'integer',
            'order' => 'integer',
            'value_type' => 'integer',
            'last_polled_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Point at a different item: forget the resolved id and old samples.
        static::updating(function (ComponentMetric $metric) {
            if ($metric->isDirty(['host', 'item_key'])) {
                $metric->itemid = null;
                $metric->value_type = null;
                $metric->points()->delete();
            }
        });
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    /** @return HasMany<MetricPoint, $this> */
    public function points(): HasMany
    {
        return $this->hasMany(MetricPoint::class);
    }

    public function auditExclude(): array
    {
        return ['itemid', 'value_type', 'last_polled_at', 'last_error'];
    }

    public function auditLabel(): string
    {
        return $this->name;
    }

    public function format(?float $value): string
    {
        if ($value === null) {
            return '–';
        }

        return number_format($value, $this->decimals).($this->unit ? ' '.$this->unit : '');
    }
}
