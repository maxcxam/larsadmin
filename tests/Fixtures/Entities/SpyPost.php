<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;
use RuntimeException;

/**
 * Counts fields() calls and exercises request key vs column and after-save callbacks.
 */
class SpyPost extends Entity
{
    public static int $calls = 0;

    public static string $model = PostModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public function fields(Request $request): array
    {
        static::$calls++;

        return [
            ID::make()->sortable(),
            Text::make('Headline', 'headline')->rules('required')->fillUsing(function ($request, $record) {
                $record->title = $request->input('headline');
                $record->status = 'draft';

                if ($request->has('boom')) {
                    return function () {
                        throw new RuntimeException('after-save failed');
                    };
                }

                return null;
            }),
        ];
    }
}
