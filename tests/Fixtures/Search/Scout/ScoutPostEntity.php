<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Scout;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;

/**
 * Opt-in entity of a Searchable model; only the title is a $search column (the SQL path).
 */
class ScoutPostEntity extends Entity
{
    public static string $model = ScoutPost::class;

    public static string $title = 'title';

    public static array $search = ['title'];

    public static bool $globallySearchable = true;

    public static ?bool $scout = null;

    public static ?Closure $indexUsing = null;

    public static ?Closure $scoutUsing = null;

    /**
     * @var list<array{0: Request, 1: object}>
     */
    public static array $scoutCalls = [];

    public static function uriKey(): string
    {
        return 'scout-posts';
    }

    public static function label(): string
    {
        return 'Scout posts';
    }

    public static function usesScout(): bool
    {
        return static::$scout ?? parent::usesScout();
    }

    public static function scoutQuery(Request $request, $search)
    {
        static::$scoutCalls[] = [$request, $search];

        return static::$scoutUsing ? (static::$scoutUsing)($search) : parent::scoutQuery($request, $search);
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$indexUsing ? (static::$indexUsing)($query) : $query;
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title'), Text::make('Body')];
    }
}
