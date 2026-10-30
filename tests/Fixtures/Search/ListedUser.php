<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

/**
 * Users listed in the panel (every user, every field) but not opted in to the global search.
 */
class ListedUser extends SearchableUser
{
    public static bool $globallySearchable = false;

    public static function uriKey(): string
    {
        return 'listed-users';
    }
}
