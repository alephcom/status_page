<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a component gets its monitored status from. The config shape depends
 * on the driver; for "zabbix_host" see App\StatusSources\ZabbixHostSource.
 */
#[Fillable(['component_id', 'driver', 'config'])]
class ComponentSource extends Model
{
    protected function casts(): array
    {
        return ['config' => 'array'];
    }

    /** @return BelongsTo<Component, $this> */
    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }
}
