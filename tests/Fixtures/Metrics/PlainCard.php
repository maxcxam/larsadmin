<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Maxcxam\Larsadmin\Metrics\Card;

/**
 * Not a Metric: a card without a data endpoint.
 */
class PlainCard extends Card
{
    public string $component = 'plain-card';
}
