<?php

namespace Maxcxam\Larsadmin\Metrics;

use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Helpers shared by the owners of cards (dashboards and entities).
 */
final class Cards
{
    /**
     * The cards the request may see, in order.
     *
     * @param  iterable<mixed>  $cards
     * @return list<Card>
     *
     * @throws InvalidArgumentException when two cards (visible or not) share a uriKey: the endpoint could not tell them apart
     */
    public static function visible(Request $request, iterable $cards): array
    {
        $keys = [];
        $visible = [];

        foreach ($cards as $card) {
            if (! $card instanceof Card) {
                continue;
            }

            $key = $card->uriKey();

            if (isset($keys[$key])) {
                throw new InvalidArgumentException("Two cards use the uriKey [{$key}] (".$card::class.'); override uriKey() on one of them.');
            }

            $keys[$key] = true;

            if ($card->authorizedToSee($request)) {
                $visible[] = $card;
            }
        }

        return $visible;
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
