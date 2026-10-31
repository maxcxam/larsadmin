<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Dashboard;

/**
 * A dashboard with cards that must never be calculated.
 */
class CanaryDashboard extends Dashboard
{
    public function uriKey(): string
    {
        return 'canary';
    }

    public function cards(Request $request): array
    {
        return [
            (new CanaryValue)->canSee(McpSpy::spy('dashboard card canSee', true)),
            new CanaryDataCard,
        ];
    }
}
