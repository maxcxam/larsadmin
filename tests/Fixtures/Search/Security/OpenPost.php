<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * Every post is listed, but the author name is searched only within TeamUser's index scope.
 */
class OpenPost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['title', 'author.name'];

    public static bool $globallySearchable = true;

    public static function uriKey(): string
    {
        return 'open-posts';
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title'), BelongsTo::make('Author', 'author', TeamUser::class)->nullable()];
    }
}
