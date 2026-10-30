<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Scoped by request state other than the user (a "current tenant"), like a Jetstream current team.
 */
class TenantMetricPost extends MetricPost
{
    public static string $tenant = 'live';

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('status', static::$tenant);
    }
}
