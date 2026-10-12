<?php

namespace App\Http\Middleware;

use App\Models\DashboardAllowedIp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureIpIsAllowed
{
    /**
     * Lets through logged-in users, and guests whose IP is on the public
     * dashboard allowlist.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() !== null || DashboardAllowedIp::allows($request->ip()), 403);

        return $next($request);
    }
}
