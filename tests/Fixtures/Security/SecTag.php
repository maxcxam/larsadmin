<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;

/**
 * Tags whose name starts with "Hidden" are outside every scope.
 */
class SecTag extends Entity
{
    public static string $model = Tag::class;

    public static string $title = 'name';

    public static array $search = ['id', 'name'];

    public static function uriKey(): string
    {
        return 'tags';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('name', 'not like', 'Hidden%');
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->sortable()->rules('required'),
        ];
    }
}
