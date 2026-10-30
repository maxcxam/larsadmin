<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;
use Maxcxam\Larsadmin\Tests\Fixtures\Tag;

class UnauditedTag extends Entity
{
    public static string $model = Tag::class;

    public static string $title = 'name';

    public static bool $auditable = false;

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Name')];
    }
}
