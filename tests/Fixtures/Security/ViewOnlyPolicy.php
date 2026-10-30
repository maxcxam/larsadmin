<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Security;

/**
 * Reads allowed; update, create, delete and the attach abilities are missing (fail closed).
 */
class ViewOnlyPolicy
{
    public function viewAny(): bool
    {
        return true;
    }

    public function view(): bool
    {
        return true;
    }
}
