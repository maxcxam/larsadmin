<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * Users whose index lists only the "@team.test" accounts; not opted in to the global search.
 */
class TeamUser extends Entity
{
    public static string $model = User::class;

    public static string $title = 'name';

    public static array $search = ['name'];

    public static function uriKey(): string
    {
        return 'team-users';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('email', 'like', '%@team.test');
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Name'), Text::make('Email')->canSee(fn () => Probe::$seeEmail)];
    }
}
