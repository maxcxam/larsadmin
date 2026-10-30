<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Filters\BooleanFilter;
use Maxcxam\Larsadmin\Filters\DateRangeFilter;
use Maxcxam\Larsadmin\Filters\SelectFilter;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

/**
 * A post with one filter of each built-in kind plus a hidden and an escaping one.
 */
class FilterPost extends Entity
{
    public static string $model = PostModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static int $perPage = 25;

    public static array $perPageOptions = [2, 25];

    public static bool $seeHidden = false;

    public static function uriKey(): string
    {
        return 'posts';
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Title')->sortable(),
            Number::make('Views')->sortable(),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            SelectFilter::make('Status', 'status')->withOptions(['draft' => 'Draft', 'live' => 'Live']),
            BooleanFilter::make('Published', 'published'),
            DateRangeFilter::make('Published At', 'published_at', 'published-range'),
            SelectFilter::make('Views', 'views', 'v')->withOptions(fn () => [10 => 'Ten', 20 => 'Twenty']),
            SelectFilter::make('Hidden', 'status', 'hidden')->withOptions(['live' => 'Live'])->canSee(fn () => static::$seeHidden),
            new class extends SelectFilter
            {
                public function key(): string
                {
                    return 'escape';
                }

                public function options(Request $request): array
                {
                    return ['1' => 'x'];
                }

                public function apply(Request $request, Builder $query, mixed $value): Builder
                {
                    return $query->orWhere('status', 'live');
                }
            },
        ];
    }
}
