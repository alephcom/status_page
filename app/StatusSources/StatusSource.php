<?php

namespace App\StatusSources;

use App\Models\ComponentSource;
use Illuminate\Support\Collection;

/**
 * A driver that turns monitoring data into component statuses.
 *
 * Drivers resolve all sources of their kind in one go, so a poll makes a
 * fixed number of API calls however many components there are.
 */
interface StatusSource
{
    /**
     * @param  Collection<int, ComponentSource>  $sources
     * @return array<int, SourceResult> keyed by ComponentSource id
     */
    public function resolve(Collection $sources): array;

    /**
     * Normalise and validate a config array as entered in the admin panel.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    public function normaliseConfig(array $config): array;
}
