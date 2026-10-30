<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

/**
 * The tenant Scout entity, not opted in.
 */
class HiddenScoutPost extends TenantScoutPost
{
    public static bool $globallySearchable = false;

    public static function uriKey(): string
    {
        return 'hidden-scout-posts';
    }
}
