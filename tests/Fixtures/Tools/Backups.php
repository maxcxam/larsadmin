<?php

namespace Maxcxam\Larsadmin\Tests\Fixtures\Tools;

use Illuminate\Http\Request;
use Maxcxam\Larsadmin\Tools\Tool;

/**
 * A tool with an icon and page props.
 */
class Backups extends Tool
{
    public string $component = 'acme/backups';

    public function icon(): ?string
    {
        return 'database';
    }

    public function props(Request $request): array
    {
        return ['disk' => 'local', 'user' => $request->user()?->email];
    }
}
