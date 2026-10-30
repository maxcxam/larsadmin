<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;

class TagEntity extends Entity
{
    public static string $model = Tag::class;

    public static string $title = 'name';

    public static array $search = ['id', 'name'];

    public static ?Closure $indexUsing = null;

    public static ?Closure $relatableUsing = null;

    public static function uriKey(): string
    {
        return 'tags';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$indexUsing ? (static::$indexUsing)($query) : $query;
    }

    public static function relatableQuery(Request $request, Builder $query): Builder
    {
        return static::$relatableUsing ? (static::$relatableUsing)($query) : $query;
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->sortable()->rules('required'),
        ];
    }
}
