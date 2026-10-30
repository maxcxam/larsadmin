<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Fields\HasMany;
use Maxcxam\Larsadmin\Fields\ID;
use Maxcxam\Larsadmin\Fields\Text;

/**
 * PanelUser whose Posts panel lists DefaultedPanelPost.
 */
class DefaultedPanelUser extends PanelUser
{
    public function fields(Request $request): array
    {
        return [
            ID::make()->sortable(),
            Text::make('Name')->sortable()->rules('required'),
            HasMany::make('Posts', 'posts', DefaultedPanelPost::class)->perPage(10),
        ];
    }
}
