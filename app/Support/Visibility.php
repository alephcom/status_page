<?php

namespace App\Support;

use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Private components are shown only to signed-in staff and to visitors from
 * allowed networks. Incidents and maintenance that only touch private
 * components are hidden from everyone else, and private component names are
 * left out of the ones that are shown.
 */
class Visibility
{
    public static function canSeePrivate(?Request $request = null): bool
    {
        $request ??= request();

        if (auth('web')->check()) {
            return true;
        }

        $ranges = array_values(array_filter(array_map('trim', (array) Setting::get('private_ip_ranges'))));

        return $ranges !== [] && $request->ip() !== null && IpUtils::checkIp($request->ip(), $ranges);
    }

    /** @param Builder<Component> $query */
    public static function components(Builder $query, bool $private): Builder
    {
        return $private ? $query : $query->public();
    }

    /**
     * Incidents or maintenance (anything with components()) that have no
     * components or at least one public one.
     *
     * @template T of Incident|Maintenance
     *
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public static function touchingPublic(Builder $query, bool $private): Builder
    {
        if ($private) {
            return $query;
        }

        return $query->where(fn (Builder $q) => $q
            ->whereDoesntHave('components')
            ->orWhereHas('components', fn (Builder $c) => $c->public()));
    }

    /**
     * Drop private components from a loaded components relation.
     *
     * @template T of Incident|Maintenance
     *
     * @param  T  $model
     * @return T
     */
    public static function scrub(Incident|Maintenance $model, bool $private): Incident|Maintenance
    {
        if (! $private && $model->relationLoaded('components')) {
            $model->setRelation('components', $model->components->reject(fn (Component $c) => $c->isPrivate())->values());
        }

        return $model;
    }

    public static function allowsIncident(Incident $incident, bool $private): bool
    {
        if (! $incident->visible) {
            return false;
        }

        return $private || $incident->components->isEmpty() || $incident->components->contains(fn (Component $c) => ! $c->isPrivate());
    }
}
