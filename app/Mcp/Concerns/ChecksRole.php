<?php

namespace App\Mcp\Concerns;

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Laravel\Mcp\Request;

/**
 * Mirrors the admin panel: editors and admins manage incidents and
 * maintenance (enforced by the EnsureStaff route middleware); component
 * overrides are admin-only.
 */
trait ChecksRole
{
    /** Hide the tool from non-admins in tools/list. */
    public function shouldRegister(): bool
    {
        return (bool) auth()->user()?->isAdmin();
    }

    protected function requireAdmin(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->isAdmin()) {
            throw new AuthorizationException('Only admins can do this.');
        }

        return $user;
    }
}
