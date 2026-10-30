<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Metrics;

use Closure;

/**
 * Knobs shared by the test metrics; tests reset them with TestMetrics::reset().
 */
final class TestMetrics
{
    /** @var class-string|object|null */
    public static mixed $source = null;

    public static int $calls = 0;

    public static ?int $ttl = null;

    public static ?string $function = null;

    public static ?string $column = null;

    public static ?string $dateColumn = null;

    public static bool $denied = false;

    /** Narrows an Entity $source through Metric::scoped() when set. */
    public static ?Closure $constraint = null;

    public static string $groupBy = 'status';

    public static function reset(): void
    {
        self::$source = null;
        self::$calls = 0;
        self::$ttl = null;
        self::$function = null;
        self::$column = null;
        self::$dateColumn = null;
        self::$denied = false;
        self::$constraint = null;
        self::$groupBy = 'status';
    }
}
