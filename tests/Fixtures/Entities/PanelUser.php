<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\HasMany;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\User as UserModel;

/**
 * A user with a Posts HasMany panel.
 */
class PanelUser extends Entity
{
    public static string $model = UserModel::class;

    public static string $title = 'name';

    public static array $search = ['id', 'name'];

    public static function uriKey(): string
    {
        return 'users';
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->sortable()->rules('required'),
            HasMany::make('Posts', 'posts', PanelPost::class)->perPage(10),
        ];
    }
}
