<?php

namespace Maxcxam\Larsadmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Larsadmin;
use Symfony\Component\HttpFoundation\Response;

class Authorize
{
    /**
     * Ensure the authenticated user may access the panel.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Larsadmin::check($request)) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => __('larsadmin::messages.forbidden')], 403);
            }

            abort(403);
        }

        return $next($request);
    }
}
