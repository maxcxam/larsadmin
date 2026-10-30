<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;

/**
 * Every post is listed; the subtitle (and optionally the title) is an attribute of the author, a TeamUser
 * whose index lists only "@team.test" accounts. No $with: the relation is eager loaded by the search.
 * The author field can be hidden by canSee (Probe::$seeAuthor).
 */
class SubtitlePost extends OpenPost
{
    public static string $title = 'title';

    public static ?string $subtitle = 'author.email';

    public static array $search = ['title'];

    public static function reset(): void
    {
        static::$title = 'title';
        static::$subtitle = 'author.email';
    }

    public static function uriKey(): string
    {
        return 'subtitle-posts';
    }

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title'),
            BelongsTo::make('Author', 'author', TeamUser::class)->nullable()->canSee(fn () => Probe::$seeAuthor),
        ];
    }
}
