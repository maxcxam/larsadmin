<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

/**
 * Opted-in tags, in the same navigation group as the posts.
 */
class OpenTag extends HiddenTag
{
    public static bool $globallySearchable = true;

    public static function uriKey(): string
    {
        return 'open-tags';
    }

    public static function label(): string
    {
        return 'Tags';
    }
}
