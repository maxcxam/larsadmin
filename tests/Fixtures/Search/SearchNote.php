<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Note;

/**
 * String-keyed notes, searched by key and title; no group (listed before grouped entities).
 */
class SearchNote extends Entity
{
    public static string $model = Note::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static bool $globallySearchable = true;

    public static function uriKey(): string
    {
        return 'notes';
    }

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title')];
    }
}
