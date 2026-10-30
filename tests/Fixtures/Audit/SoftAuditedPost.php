<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Audit;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Entity;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;

class SoftAuditedPost extends Entity
{
    public static string $model = SoftAuditPost::class;

    public static string $title = 'title';

    public function fields(Request $request): array
    {
        return [ID::make(), Text::make('Title')];
    }
}
