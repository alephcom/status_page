<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentImpact: string implements HasColor, HasLabel
{
    case None = 'none';
    case Minor = 'minor';
    case Major = 'major';
    case Critical = 'critical';

    public function getLabel(): string
    {
        return __(ucfirst($this->value));
    }

    /** Higher is more severe. */
    public function rank(): int
    {
        return match ($this) {
            self::None => 0,
            self::Minor => 1,
            self::Major => 2,
            self::Critical => 3,
        };
    }

    /**
     * This impact and everything less severe: the minimum-impact preferences
     * that still want to hear about something this severe.
     *
     * @return list<string>
     */
    public function andBelow(): array
    {
        return array_values(array_map(fn (self $i) => $i->value, array_filter(self::cases(), fn (self $i) => $i->rank() <= $this->rank())));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::None => 'gray',
            self::Minor => 'warning',
            self::Major => 'danger',
            self::Critical => 'danger',
        };
    }
}
