<?php

namespace Maxcxam\Larsadmin\Metrics;

use Illuminate\Http\Request;

/**
 * Helpers shared by the owners of cards (dashboards and entities).
 */
final class Cards
{
    /**
     * The cards the request may see, in order; the first card wins a duplicate uriKey.
     *
     * @param  iterable<mixed>  $cards
     * @return list<Card>
     */
    public static function visible(Request $request, iterable $cards): array
    {
        $visible = [];

        foreach ($cards as $card) {
            if ($card instanceof Card && $card->authorizedToSee($request)) {
                $visible[$card->uriKey()] ??= $card;
            }
        }

        return array_values($visible);
    }

    /**
     * Find a visible card by its uriKey.
     *
     * @param  iterable<mixed>  $cards
     */
    public static function find(Request $request, iterable $cards, string $uriKey): ?Card
    {
        foreach (self::visible($request, $cards) as $card) {
            if ($card->uriKey() === $uriKey) {
                return $card;
            }
        }

        return null;
    }

    /**
     * Serialize the visible cards for a page prop, pointing each at its JSON endpoint.
     *
     * @param  iterable<mixed>  $cards
     * @param  string  $route  'larsadmin.api.dashboard-metric' or 'larsadmin.api.entity-metric'
     * @param  string  $owner  the owner's uriKey (dashboard or entity)
     * @return list<array<string, mixed>>
     */
    public static function serialize(Request $request, iterable $cards, string $route, string $owner): array
    {
        return array_map(
            fn (Card $card) => $card->withEndpoint(route($route, [$owner, $card->uriKey()], false))->jsonSerialize(),
            self::visible($request, $cards),
        );
    }
}
