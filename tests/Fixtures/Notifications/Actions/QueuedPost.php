<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Notifications\Actions;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Filters\SelectFilter;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * The entity of the queued action tests (posts table).
 */
class QueuedPost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['title'];

    public static bool $auditable = false;

    /** @var list<Action> */
    public static array $actions = [];

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title'), Select::make('Status')->options(['draft' => 'Draft', 'live' => 'Live'])];
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
        QueuedAction::$calls = [];
    }
}
