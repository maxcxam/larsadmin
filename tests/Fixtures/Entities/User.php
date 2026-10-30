<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\User as UserModel;

class User extends Entity
{
    public static string $model = UserModel::class;

    public static string $title = 'name';

    public static array $search = ['id', 'name', 'email'];

    public static ?string $group = 'Access';

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->sortable()->rules('required', 'max:255'),
            Text::make('Email')->inputType('email')->rules('required', 'email', 'unique:users,email,{{id}}'),
            Password::make('Password')->creationRules('required', 'min:8'),
        ];
    }
}
