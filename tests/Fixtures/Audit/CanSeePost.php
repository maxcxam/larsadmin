<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\Date;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\File;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\User;

/**
 * Audited posts read back by viewers: a field limited by canSee, a row scope and switchable actions.
 */
class CanSeePost extends Entity
{
    public static string $model = AuditPost::class;

    public static string $title = 'title';

    public static bool $seeNote = false;

    /** @var (Closure(Builder): Builder)|null */
    public static ?Closure $scope = null;

    /** @var list<Action> */
    public static array $actions = [];

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title'),
            Textarea::make('Body')->nullable(),
            Select::make('Status')->options(['draft' => 'Чернетка', 'live' => 'Опубліковано']),
            Boolean::make('Published'),
            DateTime::make('Published at', 'published_at')->nullable(),
            Date::make('Published on', 'published_on')->nullable(),
            BelongsTo::make('Author', 'author', User::class)->nullable(),
            File::make('Attachments')->multiple()->nullable(),
            Text::make('Note')->nullable()->canSee(fn () => static::$seeNote),
            Text::make('Token')->nullable(),
            Text::make('Secret')->nullable()->onlyOnForms(),
        ];
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$scope ? (static::$scope)($query) : $query;
    }

    public function actions(Request $request): array
    {
        return static::$actions;
    }

    public static function reset(): void
    {
        static::$seeNote = false;
        static::$scope = null;
        static::$actions = [];
        static::$auditable = true;
    }
}
