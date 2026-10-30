<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * Opt-in posts: searched by key, title and author name; subtitle from the (eager loaded) author.
 */
class SearchablePost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title', 'author.name'];

    public static array $with = ['author'];

    public static bool $globallySearchable = true;

    public static ?string $subtitle = 'author.name';

    public static ?string $group = 'Content';

    public static ?Closure $indexUsing = null;

    public static function uriKey(): string
    {
        return 'posts';
    }

    public static function label(): string
    {
        return 'Posts';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$indexUsing ? (static::$indexUsing)($query) : $query;
    }

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title'),
            BelongsTo::make('Author', 'author', SearchableUser::class)->nullable(),
        ];
    }
}
