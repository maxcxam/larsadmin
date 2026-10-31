<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Tools\Tool;

/**
 * A tool whose props() must never be called.
 */
class CanaryTool extends Tool
{
    public string $component = 'acme/canary-tool';

    public function icon(): ?string
    {
        return 'database';
    }

    public function props(Request $request): array
    {
        McpSpy::$calls[] = 'tool props';

        return ['secret' => McpSpy::CANARY];
    }
}
