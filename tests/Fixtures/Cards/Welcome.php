<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Cards;

use Maxcxam\Larsadmin\Metrics\Card;

/**
 * A static card: props only, no data endpoint.
 */
class Welcome extends Card
{
    public string $component = 'acme/welcome';

    public function jsonSerialize(): array
    {
        return [...parent::jsonSerialize(), 'links' => ['docs' => '/docs']];
    }
}
