<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Maxcxam\Larsadmin\Metrics\MetricRequest;
use Maxcxam\Larsadmin\Metrics\Results\ValueResult;
use Maxcxam\Larsadmin\Metrics\Value;

/**
 * A value metric cached for 5 minutes whose calculation must never run.
 */
class CanaryValue extends Value
{
    public function calculate(MetricRequest $request): ValueResult
    {
        McpSpy::$calls[] = 'metric calculate';

        return $this->result(1);
    }

    public function cacheFor(): ?int
    {
        return 300;
    }

    public function cacheKeyParts(MetricRequest $request): array
    {
        McpSpy::$calls[] = 'metric cacheKeyParts';

        return [McpSpy::CANARY];
    }
}
