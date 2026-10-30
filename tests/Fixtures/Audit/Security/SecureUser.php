<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit\Security;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * Users as the related entity of SecurePost's BelongsTo, with a switchable row scope.
 */
class SecureUser extends Entity
{
    public static string $model = User::class;

    public static string $title = 'name';

    /** @var (Closure(Builder): Builder)|null */
    public static ?Closure $scope = null;

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$scope ? (static::$scope)($query) : $query;
    }

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Name'),
            Text::make('Email'),
        ];
    }

    public static function reset(): void
    {
        static::$scope = null;
    }
}
