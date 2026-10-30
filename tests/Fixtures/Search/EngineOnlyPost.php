<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

/**
 * Searched through the engine path only (no $search columns, so no index link).
 */
class EngineOnlyPost extends KeyOnlyPost
{
    public static array $search = [];

    public static function uriKey(): string
    {
        return 'engine-posts';
    }

    public static function usesScout(): bool
    {
        return true;
    }
}
