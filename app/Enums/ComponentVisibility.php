<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum ComponentVisibility: string implements HasLabel
{
    case Public = 'public';
    case Private = 'private';

    public function getLabel(): string
    {
        return match ($this) {
            self::Public => 'Public',
            self::Private => 'Private (staff and allowed networks only)',
        };
    }
}
