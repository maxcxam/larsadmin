<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\Filter;

/**
 * A filter computing its options in options() (it could query the database): never called by introspection.
 */
class CanaryOptionsFilter extends Filter
{
    public function options(Request $request): array
    {
        McpSpy::$calls[] = 'filter options()';

        return ['x' => McpSpy::CANARY];
    }

    public function default(): mixed
    {
        McpSpy::$calls[] = 'filter default()';

        return McpSpy::CANARY;
    }

    public function apply(Request $request, Builder $query, mixed $value): Builder
    {
        McpSpy::$calls[] = 'filter apply()';

        return $query;
    }
}
