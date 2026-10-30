<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Overrides title() and subtitle() (which then own their visibility); the index scope is DISTINCT.
 */
class CustomTextPost extends SearchablePost
{
    public static array $search = ['title'];

    public static function uriKey(): string
    {
        return 'custom-posts';
    }

    public static function indexQuery(Request $request, Builder $query): Builder
    {
        return $query->distinct();
    }

    public function title(): string
    {
        return 'Custom '.$this->record->title;
    }

    public function subtitle(): ?string
    {
        return 'Status: '.$this->record->status;
    }
}
