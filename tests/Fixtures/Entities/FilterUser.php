<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\HasMany;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\User as UserModel;

/**
 * A user with a panel of FilterPost records.
 */
class FilterUser extends Entity
{
    public static string $model = UserModel::class;

    public static string $title = 'name';

    public static function uriKey(): string
    {
        return 'users';
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name'),
            HasMany::make('Posts', 'posts', FilterPost::class)->perPage(10),
        ];
    }
}
