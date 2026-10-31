<?php

namespace Maxcxam\Larsadmin\Mcp\Introspection;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Maxcxam\Larsadmin\Mcp\McpGate;
use Maxcxam\Larsadmin\Mcp\Tools\JsonResult;
use Throwable;

/**
 * The base of the read-only introspection tools (design §6.3, §6.4): each checks McpGate when it is listed and
 * again when it runs, answers allow-listed metadata as structured JSON and never an exception message.
 *
 * @internal
 */
abstract class IntrospectionTool extends Tool
{
    /**
     * The tool is offered only while the gate is open (defense in depth, design §5.2).
     */
    public function shouldRegister(): bool
    {
        return McpGate::enabled();
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! McpGate::enabled()) {
            return Response::error((string) McpGate::reason());
        }

        try {
            return $this->introspect($request);
        } catch (Throwable $e) {
            return Response::error('Introspection failed: '.Metadata::className($e).'.');
        }
    }

    /**
     * Build the response; the gate was checked.
     */
    abstract protected function introspect(Request $request): Response|ResponseFactory;

    /**
     * The payload as structured JSON, cut to Metadata::MAX_LENGTH by dropping items of the given lists.
     *
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $lists
     */
    protected function respond(array $payload, array $lists = []): Response|ResponseFactory
    {
        return JsonResult::make(Metadata::fit($payload, $lists));
    }
}
