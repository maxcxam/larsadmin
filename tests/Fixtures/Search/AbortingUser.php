<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Users whose detail scope aborts with a configurable HTTP status (not opted in).
 */
class AbortingUser extends ListedUser
{
    public static int $status = 403;

    public static function uriKey(): string
    {
        return 'aborting-users';
    }

    public static function detailQuery(Request $request, Builder $query): Builder
    {
        abort(static::$status);
    }
}
