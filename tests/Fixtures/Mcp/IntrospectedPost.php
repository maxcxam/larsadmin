<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\BelongsTo;
use Maxcxam\Larsadmin\Fields\BelongsToMany;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Filters\BooleanFilter;
use Maxcxam\Larsadmin\Filters\DateRangeFilter;
use Maxcxam\Larsadmin\Filters\SelectFilter;
use Maxcxam\Larsadmin\Layout\Panel;
use Maxcxam\Larsadmin\Layout\Tabs;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\Rating;
use Maxcxam\Larsadmin\Tests\Fixtures\Post;

/**
 * An entity using every introspected feature, with a canary in every place introspection must not read:
 * closures (spied), option values, defaults, help, placeholders, meta, data-like rule parameters, panel
 * descriptions and confirmation texts.
 */
class IntrospectedPost extends Entity
{
    public static string $model = Post::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static array $with = ['author'];

    public static array $sort = ['id' => 'desc'];

    public static ?string $group = 'Content';

    public static bool $globallySearchable = true;

    public static ?string $subtitle = 'status';

    public static function uriKey(): string
    {
        return 'introspected-posts';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        McpSpy::$calls[] = 'indexQuery';

        return $query;
    }

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Panel::make('Main', [
                Text::make('Title')
                    ->sortable()
                    ->rules('required', 'max:255', 'in:'.McpSpy::CANARY, Rule::in([McpSpy::CANARY]), McpSpy::spy('rule closure'))
                    ->updateRules('unique:posts,title,{{id}}', 'regex:/'.McpSpy::CANARY.'/')
                    ->help(McpSpy::CANARY)
                    ->placeholder(McpSpy::CANARY)
                    ->withMeta(['secret' => McpSpy::CANARY])
                    ->default(McpSpy::spy('default'))
                    ->displayUsing(McpSpy::spy('displayUsing'))
                    ->resolveUsing(McpSpy::spy('resolveUsing')),
                Textarea::make('Body')
                    ->nullable()
                    ->readonly(McpSpy::spy('readonly', true))
                    ->hideFromIndex(McpSpy::spy('hideFromIndex', true))
                    ->default(McpSpy::CANARY)
                    ->sensitive(),
            ])->description(McpSpy::CANARY),
            Tabs::make('More', [
                Panel::make('Numbers', [
                    Number::make('Views')->min(0)->max(1000)->rules('between:1,5', 'date_format:Y-m-d', 'in:'.McpSpy::NUMERIC.',7350942', 'not_in:'.McpSpy::NUMERIC),
                    Rating::make('Score', 'views_score')->max(10),
                ]),
            ]),
            Select::make('Status')->options(['draft' => McpSpy::CANARY, 'live' => 'Live', 'archived' => 'Archived']),
            Select::make('Category', 'subtitle')
                ->options(McpSpy::spy('options', ['x' => McpSpy::CANARY]))
                ->canSee(McpSpy::spy('canSee', true)),
            Boolean::make('Published')->default(true),
            DateTime::make('Published At')->onlyOnDetail(),
            BelongsTo::make('Author', 'author', IntrospectedUser::class)->nullable(),
            BelongsToMany::make('Tags', 'tags', IntrospectedTag::class),
        ];
    }

    public function actions(Request $request): array
    {
        return [
            PublishPost::make()
                ->queued()
                ->destructive()
                ->showInline()
                ->confirmText(McpSpy::CANARY)
                ->canSee(McpSpy::spy('action canSee', true))
                ->canRun(McpSpy::spy('action canRun', true)),
        ];
    }

    public function filters(Request $request): array
    {
        return [
            SelectFilter::make('Status', 'status')->withOptions(['draft' => 'Draft', 'live' => McpSpy::CANARY])->defaultTo('draft'),
            SelectFilter::make('Author', 'author_id', 'author')
                ->withOptions(McpSpy::spy('filter options', [1 => McpSpy::CANARY]))
                ->canSee(McpSpy::spy('filter canSee', true)),
            BooleanFilter::make('Published', 'published'),
            DateRangeFilter::make('Published At', 'published_at', 'published-range'),
            new CanaryOptionsFilter,
        ];
    }

    public function cards(Request $request): array
    {
        return [
            (new CanaryValue)->width('1/2')->help(McpSpy::CANARY)->canSee(McpSpy::spy('card canSee', true)),
            new CanaryDataCard,
        ];
    }
}
