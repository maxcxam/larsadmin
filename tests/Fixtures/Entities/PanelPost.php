<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

/**
 * A post whose author points back at PanelUser (the HasMany inverse).
 */
class PanelPost extends Entity
{
    public static string $model = PostModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static ?Closure $indexUsing = null;

    public static function uriKey(): string
    {
        return 'posts';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return static::$indexUsing ? (static::$indexUsing)($query) : $query;
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Title')->sortable()->rules('required'),
            BelongsTo::make('Author', 'author', PanelUser::class)->nullable(),
            Text::make('Status')->onlyOnForms()->fillUsing(fn ($request, $record) => $record->status ??= 'draft'),
        ];
    }
}
