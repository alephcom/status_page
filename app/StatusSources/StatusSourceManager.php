<?php

namespace App\StatusSources;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

class StatusSourceManager
{
    /** @var array<string, StatusSource> */
    private array $resolved = [];

    public function __construct(private readonly Container $container) {}

    /** @return array<string, string> driver => label */
    public function drivers(): array
    {
        return collect(config('statuspage.sources'))
            ->mapWithKeys(fn (string $class, string $driver) => [$driver => method_exists($class, 'label') ? $class::label() : $driver])
            ->all();
    }

    public function driver(string $name): StatusSource
    {
        $class = config("statuspage.sources.{$name}");

        if (! $class) {
            throw new InvalidArgumentException("Unknown status source driver [{$name}].");
        }

        return $this->resolved[$name] ??= $this->container->make($class);
    }
}
