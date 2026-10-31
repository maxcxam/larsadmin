<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Extensions\Security;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Fields\Rating;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

/**
 * Posts edited with built-in fields a client extension may override (text-field, select-field) and an
 * extension field (acme/rating, 0..5 stars on "views"): the server rules must not depend on the client.
 */
class RatedPost extends Entity
{
    public static string $model = PostModel::class;

    public static string $title = 'title';

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title')->rules('required', 'max:20'),
            Select::make('Status')->options(['draft' => 'Draft', 'live' => 'Live'])->rules('required'),
            Rating::make('Views')->max(5),
            Text::make('Subtitle')->readonly(),
        ];
    }
}
