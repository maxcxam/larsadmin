<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Actions;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\SelectFilter;

/**
 * ActionPost with a status filter defaulting to "live".
 */
class DefaultedActionPost extends ActionPost
{
    public static function uriKey(): string
    {
        return 'defaulted-action-posts';
    }

    public function filters(Request $request): array
    {
        return [SelectFilter::make('Status', 'status')->withOptions(['draft' => 'Draft', 'live' => 'Live'])->defaultTo('live')];
    }
}
