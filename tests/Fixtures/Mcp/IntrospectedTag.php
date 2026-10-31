<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;

/**
 * A plain entity: globally searchable without search columns (a static warning).
 */
class IntrospectedTag extends Entity
{
    public static string $model = Tag::class;

    public static array $search = [];

    public static bool $globallySearchable = true;

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Name')];
    }
}
