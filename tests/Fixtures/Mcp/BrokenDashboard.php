<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Dashboard;
use RuntimeException;

/**
 * A dashboard whose cards() throws with a canary in the message.
 */
class BrokenDashboard extends Dashboard
{
    public bool $displayInNavigation = false;

    public function cards(Request $request): array
    {
        throw new RuntimeException(McpSpy::MESSAGE);
    }
}
