<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Search\Security;

/**
 * Shared, resettable switches and counters of the global search security fixtures.
 */
final class Probe
{
    /**
     * @var array<string, int> indexQuery() calls per entity uriKey
     */
    public static array $indexCalls = [];

    public static bool $viewAny = true;

    public static bool $seeId = true;

    public static bool $seeBody = true;

    public static bool $seeAuthor = true;

    public static bool $seeEmail = true;

    public static function reset(): void
    {
        self::$seeEmail = true;
        SubtitlePost::reset();
        self::$indexCalls = [];
        self::$viewAny = true;
        self::$seeId = true;
        self::$seeBody = true;
        self::$seeAuthor = true;
    }

    public static function called(string $uriKey): int
    {
        return self::$indexCalls[$uriKey] ?? 0;
    }

    public static function count(string $uriKey): void
    {
        self::$indexCalls[$uriKey] = self::called($uriKey) + 1;
    }
}
