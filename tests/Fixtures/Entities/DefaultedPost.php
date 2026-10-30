<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Entities;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Filters\SelectFilter;

/**
 * FilterPost with a status filter defaulting to "live".
 */
class DefaultedPost extends FilterPost
{
    public static function uriKey(): string
    {
        return 'defaulted-posts';
    }

    public function filters(Request $request): array
    {
        return [
            SelectFilter::make('Status', 'status')->withOptions(['draft' => 'Draft', 'live' => 'Live'])->defaultTo('live'),
        ];
    }
}
