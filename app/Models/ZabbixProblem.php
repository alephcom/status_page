<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Local mirror of a Zabbix problem that affected a component. */
#[Fillable(['component_id', 'eventid', 'triggerid', 'name', 'severity', 'started_at', 'resolved_at'])]
class ZabbixProblem extends Model
{
    public const SEVERITIES = ['Not classified', 'Information', 'Warning', 'Average', 'High', 'Disaster'];

    protected function casts(): array
    {
        return [
            'severity' => 'integer',
            'started_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    public function severityLabel(): string
    {
        return self::SEVERITIES[$this->severity] ?? 'Unknown';
    }
}
