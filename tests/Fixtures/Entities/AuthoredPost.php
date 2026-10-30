<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Closure;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

/**
 * A post with an author BelongsTo. Registered under the "posts" key in B3 tests.
 */
class AuthoredPost extends Entity
{
    public static string $model = PostModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static bool $required = false;

    public static ?Closure $relatableUsing = null;

    public static function uriKey(): string
    {
        return 'posts';
    }

    public function fields(Request $request): array
    {
        $author = BelongsTo::make('Author', 'author', VisibleUser::class)->searchable()->sortable();

        if (! static::$required) {
            $author->nullable();
        }

        if (static::$relatableUsing) {
            $author->relatableQueryUsing(static::$relatableUsing);
        }

        return [
            ID::make()->sortable(),
            Text::make('Title')->rules('required'),
            $author,
            Text::make('Status')->hideFromIndex()->hideFromDetail()->onlyOnForms()->fillUsing(fn ($request, $record) => $record->status ??= 'draft'),
        ];
    }
}
