<?php

use App\Http\Middleware\EnsureStaff;
use App\Mcp\Servers\StatusPageServer;
use Laravel\Mcp\Facades\Mcp;

// Authenticate with a token from `php artisan statuspage:mcp-token`.
Mcp::web('/mcp', StatusPageServer::class)
    ->middleware(['auth:sanctum', EnsureStaff::class, 'throttle:60,1']);
