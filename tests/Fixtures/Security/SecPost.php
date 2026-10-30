<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Security;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\BelongsToMany;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * Posts with status "secret" are outside every scope.
 */
class SecPost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static function uriKey(): string
    {
        return 'posts';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->where('status', '!=', 'secret');
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Title')->sortable()->rules('required'),
            BelongsTo::make('Author', 'author', SecMember::class)->nullable()->searchable(),
            BelongsToMany::make('Tags', 'tags', SecTag::class)->searchable()->perPage(10),
            Text::make('Status')->onlyOnForms()->fillUsing(fn ($request, $record) => $record->status ??= 'draft'),
        ];
    }
}
