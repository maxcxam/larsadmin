<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * An opted-in entity whose index query refuses the user.
 */
class RefusingEntity extends KeyOnlyPost
{
    public static function uriKey(): string
    {
        return 'refusing';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        throw new AuthorizationException;
    }
}
