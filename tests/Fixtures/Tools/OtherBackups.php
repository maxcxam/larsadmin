<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Tools;

use Maxcxam\Larsadmin\Tools\Tool;

/**
 * Another tool class that claims the "backups" URI key.
 */
class OtherBackups extends Tool
{
    public string $component = 'acme/other-backups';

    public function uriKey(): string
    {
        return 'backups';
    }

    public function icon(): ?string
    {
        return 'not-an-icon';
    }
}
