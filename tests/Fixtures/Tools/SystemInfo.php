<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Tools;

use Maxcxam\Larsadmin\Tools\Tool;

/**
 * A tool with the defaults: no icon, no props.
 */
class SystemInfo extends Tool
{
    public string $component = 'acme/system-info';
}
