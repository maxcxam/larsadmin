<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Metrics\Card;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

/**
 * An entity with index cards the test provides (see MetricPost::$cardList).
 */
class MetricPost extends Entity
{
    public static string $model = PostModel::class;

    /** @var array<int, Card> */
    public static array $cardList = [];

    public function fields(Request $request): array
    {
        return [Text::make('Title')];
    }

    public function cards(Request $request): array
    {
        return static::$cardList;
    }
}
