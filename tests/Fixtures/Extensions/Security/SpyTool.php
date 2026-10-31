<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Extensions\Security;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Tools\Tool;

/**
 * A tool that records whether its props were built (they hold a secret) and returns a configurable icon.
 */
class SpyTool extends Tool
{
    public string $component = 'acme/spy';

    public static int $propsCalls = 0;

    public static ?string $icon = null;

    public function icon(): ?string
    {
        return static::$icon;
    }

    public function props(Request $request): array
    {
        static::$propsCalls++;

        return ['secret' => 'tool-secret'];
    }

    public static function reset(): void
    {
        static::$propsCalls = 0;
        static::$icon = null;
    }
}
