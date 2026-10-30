<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * An opted-in entity whose index query aborts with a configurable HTTP status.
 */
class AbortingEntity extends KeyOnlyPost
{
    public static int $status = 403;

    public static function uriKey(): string
    {
        return 'aborting';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        abort(static::$status);
    }
}
