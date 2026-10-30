<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Only live posts are visible: indexQuery() must scope metrics too.
 */
class LiveMetricPost extends MetricPost
{
    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('status', 'live');
    }
}
