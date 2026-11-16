<?php

namespace App\Support;

use App\Enums\Permission;
use App\Enums\UserRole;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/** Creates the Spatie roles and permissions defined by UserRole and Permission. */
class RolesAndPermissions
{
    public static function sync(string $guard = 'web'): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::findOrCreate($permission->value, $guard);
        }

        foreach (UserRole::cases() as $role) {
            Role::findOrCreate($role->value, $guard)
                ->syncPermissions(array_map(fn (Permission $p) => $p->value, $role->permissions()));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
