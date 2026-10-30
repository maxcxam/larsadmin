<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\Boolean;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Fields\Textarea;

class SecretCastEntity extends Entity
{
    public static string $model = SecretCastPost::class;

    public static string $title = 'title';

    public function fields(Request $request): array
    {
        return [
            ID::make(),
            Text::make('Title'),
            Textarea::make('Body')->nullable(),
            Boolean::make('Published'),
            Text::make('Token')->nullable(),
            Text::make('Note')->nullable(),
            Text::make('Private')->nullable(),
        ];
    }
}
