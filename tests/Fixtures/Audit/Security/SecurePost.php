<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit\Security;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Actions\Action;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Password;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;

/**
 * The audited entity of the security suite (T1): a tenant row scope, a field limited by canSee,
 * fields that become sensitive at run time, the four masking rules and a BelongsTo to a scoped entity.
 */
class SecurePost extends Entity
{
    public static string $model = SecureAuditPost::class;

    public static string $title = 'title';

    /**
     * Attributes whose fields are ->sensitive() now.
     *
     * @var list<string>
     */
    public static array $sensitive = [];

    public static bool $seeNote = true;

    /** @var (Closure(Builder): Builder)|null */
    public static ?Closure $scope = null;

    /** @var list<Action> */
    public static array $actions = [];

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->rules('required')->sensitive(in_array('title', static::$sensitive, true)),
            Textarea::make('Body')->nullable()->sensitive(in_array('body', static::$sensitive, true)),
            Select::make('Status')->options(['draft' => 'Draft', 'live' => 'Live']),
            BelongsTo::make('Author', 'author', SecureUser::class)->nullable(),
            Text::make('Note')->nullable()->canSee(fn () => static::$seeNote),
            Text::make('Token')->nullable(),
            Textarea::make('Private')->nullable(),
            Password::make('Secret'),
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
        static::$sensitive = [];
        static::$seeNote = true;
        static::$scope = null;
        static::$actions = [];
        static::$auditable = true;
    }
}
