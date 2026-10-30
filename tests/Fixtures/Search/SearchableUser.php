<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

class SearchableUser extends Entity
{
    public static string $model = User::class;

    public static string $title = 'name';

    public static array $search = ['id', 'name', 'email'];

    public static bool $globallySearchable = true;

    public static ?string $subtitle = 'email';

    public static ?string $group = 'Access';

    public static function uriKey(): string
    {
        return 'users';
    }

    public static function label(): string
    {
        return 'Users';
    }

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Name'),
            Text::make('Email'),
        ];
    }
}
