<?php

namespace Maxcxam\Larsadmin\Mcp\Tools;

use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

/**
 * The success result of every larsadmin MCP tool, the same on every supported laravel/mcp (^0.7.1 ... ^1.0): the
 * payload as JSON text content, plus structured content where Response::structured() exists. Clients and tests
 * read the text content, which is always there.
 *
 * @internal
 */
final class JsonResult
{
    /**
     * @param  array<string, mixed>  $payload  a non-empty JSON object
     */
    public static function make(array $payload): Response|ResponseFactory
    {
        if (method_exists(Response::class, 'structured')) {
            return Response::structured($payload);
        }

        return Response::json($payload);
    }
}
