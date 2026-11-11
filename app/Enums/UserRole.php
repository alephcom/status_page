<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Editor = 'editor';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }
}
