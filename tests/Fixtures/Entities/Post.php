<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Number;
use Maxcxam\Larsadmin\Fields\Select;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

class Post extends Entity
{
    public static string $model = PostModel::class;

    public static string $title = 'title';

    public static array $search = ['id', 'title'];

    public static int $perPage = 25;

    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Title')->sortable()->rules('required', 'max:255'),
            Textarea::make('Body')->nullable(),
            Number::make('Views')->min(0)->default(0)->sortable(),
            Boolean::make('Published'),
            Select::make('Status')->options(['draft' => 'Draft', 'live' => 'Live'])->rules('required'),
            DateTime::make('Published At')->nullable(),
            Text::make('Subtitle')->hideFromIndex(),
        ];
    }
}
