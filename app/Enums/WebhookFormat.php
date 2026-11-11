<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum WebhookFormat: string implements HasLabel
{
    case Json = 'json';
    case Discord = 'discord';

    public function getLabel(): string
    {
        return match ($this) {
            self::Json => 'JSON (signed)',
            self::Discord => 'Discord',
        };
    }
}
