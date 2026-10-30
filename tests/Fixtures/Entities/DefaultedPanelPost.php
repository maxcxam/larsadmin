<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\SelectFilter;

/**
 * PanelPost with a status filter defaulting to "live".
 */
class DefaultedPanelPost extends PanelPost
{
    public function filters(Request $request): array
    {
        return [
            SelectFilter::make('Status', 'status')->withOptions(['draft' => 'Draft', 'live' => 'Live'])->defaultTo('live'),
        ];
    }
}
