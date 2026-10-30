<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * The title and the body are searched, but either field may be hidden by canSee.
 */
class SecretPost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['title', 'body'];

    public static bool $globallySearchable = true;

    public static bool $seeBody = false;

    public static bool $seeTitle = true;

    public static function uriKey(): string
    {
        return 'secret-posts';
    }

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->canSee(fn () => static::$seeTitle),
            Text::make('Body')->canSee(fn () => static::$seeBody),
        ];
    }
}
