<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum IncidentStatus: string implements HasColor, HasLabel
{
    case Investigating = 'investigating';
    case Identified = 'identified';
    case Monitoring = 'monitoring';
    case Resolved = 'resolved';

    public function getLabel(): string
    {
        return __(ucfirst($this->value));
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Investigating => 'danger',
            self::Identified => 'warning',
            self::Monitoring => 'info',
            self::Resolved => 'success',
        };
    }

    public function isResolved(): bool
    {
        return $this === self::Resolved;
    }
}
