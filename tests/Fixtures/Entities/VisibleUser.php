<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Users whose name starts with "Hidden" are outside the index scope.
 */
class VisibleUser extends User
{
    public static function uriKey(): string
    {
        return 'users';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('name', 'not like', 'Hidden%');
    }
}
