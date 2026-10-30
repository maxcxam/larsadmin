<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Security;

/**
 * Denies every ability through before(), whatever the model.
 */
class DenyAllPolicy
{
    public function before(): bool
    {
        return false;
    }

    public function viewAny(): bool
    {
        return true;
    }
}
