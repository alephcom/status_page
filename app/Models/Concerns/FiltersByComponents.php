<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * For notification recipients with a components() relation: no selection
 * means everything; otherwise only notifications touching one of their
 * components (or none at all, i.e. site-wide notices).
 */
trait FiltersByComponents
{
    /**
     * @param  Builder<static>  $query
     * @param  array<int>  $componentIds
     */
    public function scopeInterestedIn(Builder $query, array $componentIds): void
    {
        if ($componentIds === []) {
            return;
        }

        $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('components')
            ->orWhereHas('components', fn (Builder $c) => $c->whereIn('components.id', $componentIds)));
    }
}
