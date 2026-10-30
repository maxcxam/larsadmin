<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsToMany;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

/**
 * A post with a Tags BelongsToMany panel.
 */
class TaggedPost extends Entity
{
    public static string $model = PostModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static function uriKey(): string
    {
        return 'posts';
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Title')->rules('required'),
            BelongsToMany::make('Tags', 'tags', TagEntity::class)->searchable()->perPage(10),
        ];
    }
}
