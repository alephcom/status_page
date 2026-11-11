<?php

namespace App\Models\Concerns;

use App\Enums\IncidentImpact;
use Illuminate\Database\Eloquent\Builder;

/**
 * For notification recipients with a components() relation and a
 * min_impact preference: no component selection means everything;
 * otherwise only notifications touching one of their components (or none at
 * all, i.e. site-wide notices). A minimum impact skips less severe ones.
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

    /**
     * Recipients whose minimum impact is at or below this one. Null means the
     * notification is not about impact (e.g. maintenance) and goes to all.
     *
     * @param  Builder<static>  $query
     */
    public function scopeWantsImpact(Builder $query, ?IncidentImpact $impact): void
    {
        if ($impact === null) {
            return;
        }

        $query->where(fn (Builder $q) => $q->whereNull('min_impact')->orWhereIn('min_impact', $impact->andBelow()));
    }
}
