<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ComponentStatus: string implements HasColor, HasLabel
{
    case Operational = 'operational';
    case Degraded = 'degraded';
    case PartialOutage = 'partial_outage';
    case MajorOutage = 'major_outage';
    case Maintenance = 'maintenance';

    /**
     * Higher is worse. Maintenance ranks above degraded so a planned window is
     * visible, but below real outages so an outage during maintenance still shows.
     */
    public function rank(): int
    {
        return match ($this) {
            self::Operational => 0,
            self::Degraded => 1,
            self::Maintenance => 2,
            self::PartialOutage => 3,
            self::MajorOutage => 4,
        };
    }

    public function getLabel(): string
    {
        return __(match ($this) {
            self::Operational => 'Operational',
            self::Degraded => 'Degraded performance',
            self::PartialOutage => 'Partial outage',
            self::MajorOutage => 'Major outage',
            self::Maintenance => 'Under maintenance',
        });
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Operational => 'success',
            self::Degraded => 'warning',
            self::PartialOutage => 'warning',
            self::MajorOutage => 'danger',
            self::Maintenance => 'info',
        };
    }

    /** CSS modifier used by the public page. */
    public function css(): string
    {
        return str_replace('_', '-', $this->value);
    }

    public function isOperational(): bool
    {
        return $this === self::Operational;
    }

    /**
     * @param  iterable<self|null>  $statuses
     */
    public static function worst(iterable $statuses): self
    {
        $worst = self::Operational;

        foreach ($statuses as $status) {
            if ($status !== null && $status->rank() > $worst->rank()) {
                $worst = $status;
            }
        }

        return $worst;
    }

    /**
     * Statuses ordered from best to worst, e.g. for uptime breakdowns.
     *
     * @return list<self>
     */
    public static function ordered(): array
    {
        $cases = self::cases();
        usort($cases, fn (self $a, self $b) => $a->rank() <=> $b->rank());

        return $cases;
    }
}
