<?php

namespace App\Filament\Concerns;

use App\Enums\Permission;

/** Show a resource or page only to users with its permission. */
trait RequiresPermission
{
    abstract protected static function requiredPermission(): Permission;

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->allows(static::requiredPermission());
    }
}
