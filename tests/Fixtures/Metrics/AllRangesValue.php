<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

/**
 * PostsValue with a 7 day and an unbounded range as well.
 */
class AllRangesValue extends PostsValue
{
    public function uriKey(): string
    {
        return 'posts-value';
    }

    public function ranges(): array
    {
        return $this->labelled([30, 7, 'TODAY', 'MTD', 'YTD', 'ALL']);
    }
}
