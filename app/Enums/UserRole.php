<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Names of the Spatie roles. Users without a role are public accounts. */
enum UserRole: string implements HasLabel
{
    case Admin = 'admin';
    case Editor = 'editor';

    public function getLabel(): string
    {
        return ucfirst($this->value);
    }

    /** @return list<Permission> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => Permission::cases(),
            self::Editor => [
                Permission::AccessAdmin,
                Permission::ViewPrivate,
                Permission::ManageIncidents,
                Permission::ManageMaintenance,
                Permission::ViewSlaReports,
                Permission::ManageOwnApiTokens,
            ],
        };
    }
}
