<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Mcp;

use Closure;

/**
 * Records every host callback of the introspection fixtures that introspection must never run, and holds the
 * canary markers they return (ADR-038).
 */
final class McpSpy
{
    public const string CANARY = 'CANARY-SPY-c41f';

    public const string ROW = 'CANARY-ROW-7f3a';

    public const string MESSAGE = 'CANARY-MSG-90bd';

    /** A numeric marker: record ids in in: / not_in: rules must not be returned. */
    public const string NUMERIC = '7350941';

    /** @var list<string> */
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }

    /**
     * A closure that records its name when called and returns $value (default: the canary).
     */
    public static function spy(string $name, mixed $value = self::CANARY): Closure
    {
        return function (mixed ...$arguments) use ($name, $value) {
            self::$calls[] = $name;

            return $value;
        };
    }
}
