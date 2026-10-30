<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * An opted-in entity whose index query throws.
 */
class BrokenEntity extends KeyOnlyPost
{
    public static function uriKey(): string
    {
        return 'broken';
    }

    public static function label(): string
    {
        return 'Broken';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        throw new RuntimeException('The index query failed.');
    }
}
