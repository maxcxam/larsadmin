<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

/**
 * Opted in without any $search column and without Scout: silently skipped.
 */
class UnsearchablePost extends KeyOnlyPost
{
    public static array $search = [];

    public static function uriKey(): string
    {
        return 'unsearchable-posts';
    }
}
