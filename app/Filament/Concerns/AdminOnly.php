<?php

namespace App\Filament\Concerns;

/** Restrict a resource or page to admins; editors handle incidents and maintenance only. */
trait AdminOnly
{
    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }
}
