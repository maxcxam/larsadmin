<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;

/**
 * Searchable on its index, but not opted in to the global search.
 */
class HiddenTag extends Entity
{
    public static string $model = Tag::class;

    public static string $title = 'name';

    public static array $search = ['id', 'name'];

    public static ?string $group = 'Content';

    public static function uriKey(): string
    {
        return 'tags';
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Name')];
    }
}
