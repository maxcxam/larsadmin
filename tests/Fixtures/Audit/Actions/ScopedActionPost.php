<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit\Actions;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\Number;

/**
 * An action entity whose row scope adds a computed column (as withCount) or selects a subset.
 */
class ScopedActionPost extends ActionAuditedPost
{
    /** @var (Closure(Builder): Builder)|null */
    public static ?Closure $scope = null;

    public static function uriKey(): string
    {
        return 'scoped-action-posts';
    }

    public function fields(Request $request): array
    {
        return [...parent::fields($request), Number::make('Comments', 'comments_count')->exceptOnForms()];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$scope ? (static::$scope)($query) : $query;
    }

    public static function reset(): void
    {
        parent::reset();
        static::$scope = null;
    }
}
