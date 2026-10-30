<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class OpenPost extends Post
{
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('status', 'live');
    }

    public static function detailQuery(Request $request, Builder $query): Builder
    {
        return $query;
    }
}
