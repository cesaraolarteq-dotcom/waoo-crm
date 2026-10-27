<?php
namespace App\Http\Middleware;

use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;

/** Keep Laravel's existing CORS behavior elsewhere; this capability is strictly server-to-server. */
final class ServerAvailabilityCors extends HandleCors
{
    protected function hasMatchingPath(Request $request): bool
    {
        if($request->is('api/availability','api/availability/*'))return false;
        return parent::hasMatchingPath($request);
    }
}
