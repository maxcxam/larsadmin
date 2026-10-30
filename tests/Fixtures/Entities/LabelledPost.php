<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\DateTime;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Post as PostModel;

class LabelledPost extends Entity
{
    public static string $model = PostModel::class;

    public static string $title = 'title';

    public static ?string $label = 'Дописи';

    public static ?string $singularLabel = 'Допис';

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Заголовок', 'title'),
            DateTime::make('Опубліковано', 'published_at'),
        ];
    }
}
