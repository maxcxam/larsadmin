<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Tools;

use Maxcxam\Larsadmin\Tools\Tool;

/**
 * A tool whose component is not an extension key.
 */
class BuiltinKeyTool extends Tool
{
    public string $component = 'text-field';
}
