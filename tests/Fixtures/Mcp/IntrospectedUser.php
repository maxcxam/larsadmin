<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\HasMany;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\User;

/**
 * The related entity of IntrospectedPost::author, with a relation panel resolved from the model.
 */
class IntrospectedUser extends Entity
{
    public static string $model = User::class;

    public static string $title = 'name';

    public static array $search = ['name', 'email'];

    public static bool $displayInNavigation = false;

    public static bool $auditable = false;

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Name')->rules('required', 'max:120'),
            Text::make('Email')->rules('required', 'email', 'unique:users,email,{{id}}'),
            Password::make('Password')->onlyOnForms(),
            HasMany::make('Posts', 'posts'),
        ];
    }
}
