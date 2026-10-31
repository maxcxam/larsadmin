<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Cards;

use Illuminate\Auth\Access\AuthorizationException;
use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;

/**
 * A data card without parameters; it can be told to deny access from data().
 */
class StatusCard extends DataCard
{
    public string $component = 'acme/status';

    public static bool $deny = false;

    public function data(CardRequest $request): mixed
    {
        if (static::$deny) {
            throw new AuthorizationException;
        }

        return ['ok' => true, 'ratio' => 1.0, 'empty' => (object) [], 'owner' => $request->owner, 'timezone' => $request->timezone];
    }
}
