<?php

namespace Maxcxam\Larsadmin\Mcp\Introspection;

use Closure;
use DateInterval;
use DateTimeInterface;
use Maxcxam\Larsadmin\Metrics\Card;
use Maxcxam\Larsadmin\Metrics\DataCard;
use Maxcxam\Larsadmin\Metrics\Metric;
use Maxcxam\Larsadmin\Metrics\Partition;
use Maxcxam\Larsadmin\Metrics\Progress;
use Maxcxam\Larsadmin\Metrics\Trend;
use Maxcxam\Larsadmin\Metrics\Value;
use Throwable;

/**
 * The allow-listed metadata of a card or metric (ADR-038): never calculate(), data(), its help text or meta.
 *
 * @internal
 */
final class CardMetadata
{
    /**
     * @param  iterable<mixed>  $cards
     * @return list<array<string, mixed>>
     */
    public static function describeAll(iterable $cards): array
    {
        $described = [];

        foreach ($cards as $card) {
            if ($card instanceof Card) {
                $described[] = self::describe($card);
            }
        }

        return $described;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(Card $card): array
    {
        $described = [
            'uriKey' => self::safe(fn () => $card->uriKey()),
            'class' => Metadata::className($card),
            'file' => Metadata::classFile($card),
            'name' => self::safe(fn () => $card->name()),
            'type' => self::type($card),
            'component' => $card->component,
            'width' => $card->width,
            'canSee' => Metadata::property($card, 'canSee', Card::class) instanceof Closure,
        ];

        if ($card instanceof Metric) {
            $described['ranges'] = self::ranges($card);
        }

        if ($card instanceof Metric || $card instanceof DataCard) {
            $described['cacheFor'] = self::cacheFor($card);
        }

        return $described;
    }

    private static function type(Card $card): string
    {
        return match (true) {
            $card instanceof Value => 'value',
            $card instanceof Trend => 'trend',
            $card instanceof Partition => 'partition',
            $card instanceof Progress => 'progress',
            $card instanceof Metric => 'metric',
            $card instanceof DataCard => 'data',
            default => 'card',
        };
    }

    /**
     * The range keys (days or TODAY/MTD/QTD/YTD/ALL), without their labels.
     *
     * @return list<int|string>|null
     */
    private static function ranges(Metric $metric): ?array
    {
        try {
            $keys = array_keys($metric->ranges());
        } catch (Throwable) {
            return null;
        }

        return array_values(array_filter(
            $keys,
            fn ($key) => is_int($key) || in_array($key, ['TODAY', 'MTD', 'QTD', 'YTD', 'ALL'], true),
        ));
    }

    /**
     * The cache lifetime in seconds, "interval" / "until" for a DateInterval / DateTime, null when not cached.
     */
    private static function cacheFor(Metric|DataCard $card): int|string|null
    {
        try {
            $ttl = $card->cacheFor();
        } catch (Throwable) {
            return null;
        }

        return match (true) {
            is_int($ttl) => $ttl,
            $ttl instanceof DateInterval => 'interval',
            $ttl instanceof DateTimeInterface => 'until',
            default => null,
        };
    }

    private static function safe(Closure $callback): ?string
    {
        try {
            $value = $callback();
        } catch (Throwable) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
