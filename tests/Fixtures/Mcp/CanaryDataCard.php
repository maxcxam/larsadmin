<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;

/**
 * A data card whose data() must never run; its meta holds a canary.
 */
class CanaryDataCard extends DataCard
{
    public string $component = 'acme/canary';

    public string $width = 'full';

    public function __construct()
    {
        $this->withMeta(['secret' => McpSpy::CANARY]);
    }

    public function data(CardRequest $request): mixed
    {
        McpSpy::$calls[] = 'card data';

        return McpSpy::CANARY;
    }
}
