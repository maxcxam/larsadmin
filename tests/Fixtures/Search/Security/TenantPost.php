<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * Posts scoped to their author (one tenant per user); key, title, body and author name are searched,
 * and the key, body and author fields can be hidden by canSee (Probe switches). The subtitle is the body.
 */
class TenantPost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title', 'body', 'author.name'];

    public static array $with = ['author'];

    public static bool $globallySearchable = true;

    public static ?string $subtitle = 'body';

    public static function uriKey(): string
    {
        return 'tenant-posts';
    }

    public static function label(): string
    {
        return 'Tenant posts';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        Probe::count(static::uriKey());

        return $query->where('author_id', $request->user()?->getAuthIdentifier() ?? 0);
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->canSee(fn () => Probe::$seeId),
            Text::make('Title'),
            Text::make('Body')->canSee(fn () => Probe::$seeBody),
            BelongsTo::make('Author', 'author', TeamUser::class)->nullable()->canSee(fn () => Probe::$seeAuthor),
        ];
    }
}
