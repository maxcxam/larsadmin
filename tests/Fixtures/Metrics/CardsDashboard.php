<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Dashboards\Dashboard;
use Maxcxam\Larsadmin\Metrics\Card;

/**
 * A dashboard whose cards the test provides.
 */
class CardsDashboard extends Dashboard
{
    /** @var array<int, Card> */
    public array $cardList = [];

    public function cards(Request $request): array
    {
        return $this->cardList;
    }
}
