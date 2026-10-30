<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit\Actions;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Tests\Fixtures\Audit\AuditPost;

class ActionAuditedPost extends Entity
{
    public static string $model = AuditPost::class;

    public static string $title = 'title';

    /** @var list<Action> */
    public static array $actions = [];

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title'),
            Textarea::make('Body')->nullable(),
            Select::make('Status')->options(['draft' => 'Draft', 'live' => 'Live']),
            Text::make('Note')->nullable()->sensitive(),
        ];
    }

    public function actions(Request $request): array
    {
        return static::$actions;
    }

    public static function reset(): void
    {
        static::$actions = [];
        static::$auditable = true;
    }
}
