<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Cards;

use Illuminate\Database\Eloquent\Builder;
use Maxcxam\Larsadmin\Metrics\CardRequest;
use Maxcxam\Larsadmin\Metrics\DataCard;
use Maxcxam\Larsadmin\Tests\Fixtures\Entities\ScopedPost;

/**
 * A data card on DataCard::entityQuery(): the titles of the posts ScopedPost lets the user list ("live" only),
 * narrowed by a constraint that tries to escape the scope with an "or".
 */
class ScopedTitles extends DataCard
{
    public string $component = 'acme/scoped-titles';

    /** The entity given to entityQuery(): a class or a uriKey. */
    public static string $entity = ScopedPost::class;

    public function data(CardRequest $request): mixed
    {
        return $this->entityQuery($request, static::$entity, fn (Builder $query) => $query->where('title', 'like', 'A%')->orWhere('title', 'Draft B'))
            ->orderBy('id')
            ->pluck('title')
            ->all();
    }
}
