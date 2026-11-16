<?php

namespace App\Console\Commands;

use App\Support\RolesAndPermissions;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('statuspage:sync-roles')]
#[Description('Create the admin and editor roles and their permissions')]
class SyncRoles extends Command
{
    public function handle(): int
    {
        RolesAndPermissions::sync();
        $this->info('Roles and permissions are up to date.');

        return self::SUCCESS;
    }
}
