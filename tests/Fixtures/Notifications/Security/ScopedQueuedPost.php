<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Filters\SelectFilter;
use Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions\QueuedAction;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * Posts whose title starts with "hidden" are outside the row scope (index and detail), as are those of
 * the statuses listed in $hiddenStatuses (to move records out of the scope between dispatch and run).
 */
class ScopedQueuedPost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['title'];

    public static bool $auditable = false;

    /** @var list<Action> */
    public static array $actions = [];

    /** @var list<string> */
    public static array $hiddenStatuses = [];

    public static function uriKey(): string
    {
        return 'scoped-posts';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('title', 'not like', 'hidden%')
            ->when(static::$hiddenStatuses !== [], fn (Builder $query) => $query->whereNotIn('status', static::$hiddenStatuses));
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title'), Text::make('Status')];
    }

    public function filters(Request $request): array
    {
        return [SelectFilter::make('Status', 'status')->withOptions(['draft' => 'Draft', 'live' => 'Live'])];
    }

    public function actions(Request $request): array
    {
        return static::$actions;
    }

    public static function reset(): void
    {
        static::$actions = [];
        static::$auditable = false;
        static::$hiddenStatuses = [];
        QueuedAction::$calls = [];
    }
}
