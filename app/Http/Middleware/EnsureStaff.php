<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Only admins and editors (users with a role) may use the MCP endpoint. */
class EnsureStaff
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->role !== null, 403, 'This token does not belong to a status page admin or editor.');

        return $next($request);
    }
}
