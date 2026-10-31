<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * An entity whose fields depend on the user: without one (MCP) it fails with an Error.
 */
class UserBoundEntity extends Entity
{
    public static string $model = Post::class;

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->readonly($request->user()->is_admin !== true),
        ];
    }
}
