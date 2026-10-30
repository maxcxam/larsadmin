<?php

namespace Maxcxam\Larsadmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Maxcxam\Larsadmin\Larsadmin;
use Symfony\Component\HttpFoundation\Response;

class Authenticate
{
    /**
     * Ensure the request is made by a user authenticated on the panel guard.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = Larsadmin::guard();

        if (Auth::guard($guard)->guest()) {
            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return response()->json(['message' => __('larsadmin::messages.unauthenticated')], 401);
            }

            return redirect()->guest(route('larsadmin.login'));
        }

        Auth::shouldUse($guard ?? Auth::getDefaultDriver());

        return $next($request);
    }
}
