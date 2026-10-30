<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * Searched by the integer key only (the Entity default $search).
 */
class KeyOnlyPost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static bool $globallySearchable = true;

    public static function uriKey(): string
    {
        return 'key-posts';
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title')];
    }
}
