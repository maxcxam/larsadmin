<?php

namespace Maxcxam\Larsadmin\Http\Controllers;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Realtime\Realtime;
use Symfony\Component\HttpFoundation\Response;

/**
 * The panel's own channel authorization (ADR-027): only "private-{prefix}..." channels, authorized by the
 * panel guard's session, never the host's channels (403). 404 while realtime is off.
 *
 * @internal Not part of the public API.
 */
class BroadcastAuthController
{
    public function __invoke(Request $request, BroadcastManager $broadcast): Response
    {
        abort_unless(Realtime::enabled(), 404);

        $channel = $request->input('channel_name');
        $socket = $request->input('socket_id');

        abort_unless(
            is_string($channel)
            && strlen($channel) <= 200
            && str_starts_with($channel, 'private-'.Realtime::prefix())
            && is_string($socket)
            && preg_match('/\A\d{1,20}\.\d{1,20}\z/', $socket) === 1,
            403,
        );

        // No JSONP: the answer is always JSON.
        $request->query->remove('callback');
        $request->request->remove('callback');

        // Idempotent: the provider registers it when the broadcaster is resolved with realtime on.
        Realtime::registerChannel($broadcast);

        $result = $broadcast->connection()->auth($request);

        $response = $result instanceof Response ? $result : response()->json($result);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
